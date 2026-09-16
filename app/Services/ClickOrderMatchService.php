<?php

namespace App\Services;

use App\Models\ShopeeOrder;
use App\Models\UserActivity;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Nối cú bấm link trên web (short_link_click — khách bấm link trong reel/bình luận Facebook,
 * đi qua /go/{code} sang Shopee) với đơn trong báo cáo hoa hồng Shopee, để trả lời đúng một
 * câu: cú click đó có ra đơn hay không.
 *
 * Không có ID chung giữa hai bên. Báo cáo Shopee chỉ cho "Thời gian click" và tên sản phẩm;
 * ô Sub_id phân biệt được KHÁCH chứ không phân biệt được từng cú bấm. Nên ghép bằng đúng hai
 * dấu hiệu đó.
 *
 * Đo trên dữ liệu production ngày 2026-09-16: 6/11 dòng đơn khớp đúng một cú click, lệch
 * 0–13 phút; 5 dòng trượt đều là đơn PHÁT SINH TRƯỚC khi web ghi tên sản phẩm vào log click
 * (trước 09-09) hoặc khách mua sản phẩm khác với link đã bấm.
 *
 * `clicked_at` của Shopee là mốc cấp ĐƠN — đơn nhiều sản phẩm thì mọi dòng mang cùng một mốc,
 * là lúc khách bấm link trước khi đặt. Vì thế cửa sổ ghép lùi về quá khứ rộng (khách bấm link,
 * xem hàng một lúc rồi mới đặt) và tiến về tương lai rất hẹp.
 */
class ClickOrderMatchService
{
    /** Cú click được phép xảy ra trước mốc click của Shopee bao nhiêu phút. */
    private const WINDOW_BEFORE_MINUTES = 30;

    /** ...và sau bao nhiêu phút (chỉ để dung sai lệch đồng hồ giữa hai hệ thống). */
    private const WINDOW_AFTER_MINUTES = 2;

    /**
     * Khách bấm link sản phẩm A rồi mua sản phẩm B trong cùng phiên — Shopee vẫn trả hoa hồng,
     * nhưng tên sản phẩm không còn khớp được. Chỉ dám ghép kiểu này khi hai mốc gần như trùng
     * nhau, vì lúc đó tên sản phẩm không còn giúp phân biệt hai khách bấm gần nhau.
     */
    private const CROSS_SELL_WINDOW_MINUTES = 2;

    /**
     * @param  Collection<int, UserActivity>  $clicks  Các sự kiện short_link_click cần đối chiếu.
     * @param  CarbonInterface|null  $from  Khoảng ngày admin đang xem — dùng để đếm cả những đơn
     *                                      KHÔNG đến từ cú bấm nào (unmatched_orders). Bỏ trống
     *                                      thì chỉ nhìn quanh tập click.
     * @return array{
     *     by_click: array<int, array{order_id: string, status: string, commission: float, product_name: ?string, ordered_at: ?string, cross_sell: bool}>,
     *     by_product: array<string, array{orders: int, commission: float}>,
     *     orders: int,
     *     commission: float,
     *     unmatched_orders: int
     * }
     */
    public function match(Collection $clicks, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $orders = $this->ordersInWindow($clicks, $from, $to);

        if ($orders->isEmpty()) {
            return ['by_click' => [], 'by_product' => [], 'orders' => 0, 'commission' => 0.0, 'unmatched_orders' => 0];
        }

        $byClick = [];
        $taken = [];

        foreach ($orders as $order) {
            $click = $this->bestClickFor($order, $clicks, $taken);

            if ($click === null) {
                continue;
            }

            $taken[$click['id']] = true;
            $byClick[$click['id']] = [
                'order_id' => $order['order_id'],
                'status' => $order['status'],
                'commission' => $order['commission'],
                'product_name' => $order['product_names'][0] ?? null,
                'ordered_at' => $order['ordered_at'],
                'cross_sell' => $click['cross_sell'],
            ];
        }

        return [
            'by_click' => $byClick,
            'by_product' => $this->groupByClickedProduct($clicks, $byClick),
            'orders' => count($byClick),
            'commission' => (float) array_sum(array_column($byClick, 'commission')),
            'unmatched_orders' => $orders->count() - count($byClick),
        ];
    }

    /**
     * Đơn (đã gộp các dòng sản phẩm theo order_id) cần đối chiếu: rộng bằng khoảng ngày admin
     * đang xem — để đơn KHÔNG đến từ cú bấm nào cũng lọt vào và được đếm — nhưng không bao giờ
     * hẹp hơn tầm với của tập click.
     *
     * Gộp theo đơn chứ không để rời từng dòng vì Shopee dồn hoa hồng cấp đơn vào dòng đầu, và
     * cả đơn chỉ đến từ MỘT cú bấm link.
     *
     * @param  Collection<int, UserActivity>  $clicks
     * @return Collection<int, array{order_id: string, status: string, commission: float, product_names: list<string>, clicked_at: Carbon, ordered_at: ?string}>
     */
    private function ordersInWindow(Collection $clicks, ?CarbonInterface $from, ?CarbonInterface $to): Collection
    {
        $clickStart = $clicks->isEmpty() ? null : $clicks->min('created_at')->copy()->subMinutes(self::WINDOW_AFTER_MINUTES);
        $clickEnd = $clicks->isEmpty() ? null : $clicks->max('created_at')->copy()->addMinutes(self::WINDOW_BEFORE_MINUTES);

        $start = $from && $clickStart ? $from->copy()->min($clickStart) : ($from ?: $clickStart);
        $end = $to && $clickEnd ? $to->copy()->max($clickEnd) : ($to ?: $clickEnd);

        // Không có click nào mà cũng không có khoảng ngày thì không có gì để đối chiếu — đọc cả
        // bảng đơn ở đây là vô nghĩa và tốn.
        if ($start === null && $end === null) {
            return collect();
        }

        return ShopeeOrder::whereNotNull('clicked_at')
            ->when($start, fn ($q) => $q->where('clicked_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('clicked_at', '<=', $end))
            ->get(['order_id', 'product_name', 'net_commission', 'status', 'clicked_at', 'ordered_at'])
            ->groupBy('order_id')
            ->map(fn (Collection $lines) => [
                'order_id' => (string) $lines->first()->order_id,
                'status' => (string) $lines->first()->status,
                'commission' => (float) $lines->sum('net_commission'),
                'product_names' => $lines->pluck('product_name')->filter()->values()->all(),
                'clicked_at' => $lines->first()->clicked_at,
                'ordered_at' => $lines->first()->ordered_at?->toDateTimeString(),
            ])
            ->sortBy('clicked_at')
            ->values();
    }

    /**
     * Cú click đứng sau đơn này. Ưu tiên tuyệt đối cho click ĐÚNG SẢN PHẨM — chỉ khi không có
     * mới xét tới khả năng khách mua sang sản phẩm khác, và khi đó siết chặt cửa sổ thời gian.
     * Mỗi click chỉ được gán cho một đơn ($taken), nếu không một cú bấm sẽ bị đếm nhiều lần khi
     * khách đặt liên tiếp hai đơn.
     *
     * @param  array{clicked_at: Carbon, product_names: list<string>, ...}  $order
     * @param  Collection<int, UserActivity>  $clicks
     * @param  array<int, true>  $taken
     * @return array{id: int, cross_sell: bool}|null
     */
    private function bestClickFor(array $order, Collection $clicks, array $taken): ?array
    {
        $clickedAt = $order['clicked_at'];
        $names = array_map(fn (string $name) => $this->normalize($name), $order['product_names']);

        $inWindow = $clicks
            ->reject(fn (UserActivity $c) => isset($taken[$c->id]))
            ->filter(fn (UserActivity $c) => $c->created_at->between(
                $clickedAt->copy()->subMinutes(self::WINDOW_BEFORE_MINUTES),
                $clickedAt->copy()->addMinutes(self::WINDOW_AFTER_MINUTES),
            ))
            ->sortBy(fn (UserActivity $c) => abs($c->created_at->diffInSeconds($clickedAt)));

        $sameProduct = $inWindow->first(
            fn (UserActivity $c) => $c->product_name && in_array($this->normalize($c->product_name), $names, true)
        );

        if ($sameProduct) {
            return ['id' => $sameProduct->id, 'cross_sell' => false];
        }

        $crossSell = $inWindow->first(fn (UserActivity $c) => $c->created_at->between(
            $clickedAt->copy()->subMinutes(self::CROSS_SELL_WINDOW_MINUTES),
            $clickedAt->copy()->addMinutes(self::CROSS_SELL_WINDOW_MINUTES),
        ));

        return $crossSell ? ['id' => $crossSell->id, 'cross_sell' => true] : null;
    }

    /**
     * Quy đơn về SẢN PHẨM CỦA CÚ CLICK (không phải sản phẩm ghi trên đơn) — đây là con số trả
     * lời "link sản phẩm này đưa được bao nhiêu đơn về", kể cả khi khách rẽ sang mua món khác.
     *
     * @param  Collection<int, UserActivity>  $clicks
     * @param  array<int, array{commission: float, ...}>  $byClick
     * @return array<string, array{orders: int, commission: float}>
     */
    private function groupByClickedProduct(Collection $clicks, array $byClick): array
    {
        $byProduct = [];

        foreach ($clicks as $click) {
            $order = $byClick[$click->id] ?? null;

            if ($order === null || ! $click->product_name) {
                continue;
            }

            $name = (string) $click->product_name;
            $byProduct[$name] ??= ['orders' => 0, 'commission' => 0.0];
            $byProduct[$name]['orders']++;
            $byProduct[$name]['commission'] += $order['commission'];
        }

        return $byProduct;
    }

    /** Tên sản phẩm hai bên gõ khác khoảng trắng/hoa thường là chuyện thường, chuẩn hoá trước khi so. */
    private function normalize(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }
}
