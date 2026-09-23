<?php

namespace App\Http\Controllers;

use App\Services\TrackingService;
use App\Services\VoucherCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Trang mã giảm giá toàn sàn (/ma-giam-gia) — khách xem và lấy mã mà KHÔNG phải dán link
 * sản phẩm, khác hẳn công cụ ở trang chủ. Đây là trang có URL riêng để đi SEO và để dán
 * vào bài đăng.
 *
 * `index` trả trang đầu kèm luôn mã (không để frontend gọi thêm một lượt nữa mới có gì
 * hiện) — quan trọng cho cả tốc độ hiện nội dung lẫn việc bọ tìm kiếm đọc được mã ngay
 * trong HTML đầu tiên. Từ trang 2 trở đi mới gọi `feed` khi khách cuộn.
 */
class VoucherCatalogController extends Controller
{
    public function __construct(private VoucherCatalogService $catalog) {}

    public function index(Request $request, TrackingService $tracking): Response
    {
        $tracking->log('page_view', $request, ['url' => $request->fullUrl()]);

        $filters = $this->filters($request);

        return Inertia::render('Vouchers', [
            'initial' => $this->catalog->list(
                $filters['platform'],
                $filters['keyword'],
                1,
                VoucherCatalogService::PAGE_SIZE,
                $request->user(),
            ),
            'filters' => $filters,
            'counts' => $this->catalog->countsByPlatform(),
            'lastSyncedAt' => $this->catalog->lastSyncedAt(),
        ]);
    }

    /** Nguồn dữ liệu cho cuộn vô tận và cho việc đổi sàn / gõ tìm kiếm. */
    public function feed(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->json($this->catalog->list(
            $filters['platform'],
            $filters['keyword'],
            (int) $request->integer('page', 1),
            (int) $request->integer('page_size', VoucherCatalogService::PAGE_SIZE),
            $request->user(),
        ));
    }

    /**
     * Sàn chỉ nhận đúng danh sách trong config: `platform` đi thẳng vào câu WHERE, và để
     * ngỏ thì URL nào cũng thành một truy vấn riêng, vừa vô nghĩa vừa mở đường cho việc
     * đúc hàng loạt URL rác trỏ vào trang.
     *
     * @return array{platform: string, keyword: string}
     */
    private function filters(Request $request): array
    {
        $platforms = (array) config('services.voucher_catalog.platforms', []);

        $validated = $request->validate([
            'platform' => ['nullable', 'string', Rule::in([...$platforms, 'all'])],
            'keyword' => ['nullable', 'string', 'max:100'],
        ]);

        return [
            'platform' => $validated['platform'] ?? 'all',
            'keyword' => trim($validated['keyword'] ?? ''),
        ];
    }
}
