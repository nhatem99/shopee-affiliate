<?php

namespace App\Console\Commands;

use App\Services\KieuShopeeService;
use Illuminate\Console\Command;

/**
 * Gọi thử nguồn lấy mã từ chính server đang chạy.
 *
 * Điểm gãy hay gặp nhất của nguồn này là `next_action` — ID Server Action do bản build
 * Next.js của họ sinh ra, cứ deploy lại là đổi. Command này cho biết ngay "còn chạy không"
 * trong 1 lệnh, thay vì phải tự dựng curl với đủ header + multipart.
 */
class KieuShopeeCheck extends Command
{
    protected $signature = 'kieushopee:check {url : Link sản phẩm Shopee dùng để thử}';

    protected $description = 'Gọi thử sansale.kieushopee.com từ server này và in kết quả';

    public function handle(KieuShopeeService $kieuShopee): int
    {
        $this->line('Đang gọi '.config('services.kieushopee.endpoint').'...');
        $this->newLine();

        $startedAt = microtime(true);
        // Bỏ qua cache — nếu không, lần chạy thứ hai chỉ đọc lại kết quả cũ và
        // không nói được gì về tình trạng hiện tại của nguồn.
        $result = $kieuShopee->fetchProductAndVoucherLink($this->argument('url'), useCache: false);
        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        if ($result === null) {
            $this->error("❌ Không lấy được link ({$durationMs}ms).");
            $this->line('Lý do cụ thể nằm ở storage/logs/laravel.log (tìm "KieuShopeeService").');
            $this->line('Nghi ngờ đầu tiên: next_action đã đổi — lấy ID mới ở tab Network của site nguồn.');

            return self::FAILURE;
        }

        $this->info("✅ OK ({$durationMs}ms)");
        $this->table(['Trường', 'Giá trị'], [
            ['Link đã áp mã', $result['voucher_link']],
            ['shop_id / item_id', ($result['shop_id'] ?? '—').' / '.($result['item_id'] ?? '—')],
            ['Tên sản phẩm', $result['product']['product_name'] ?? '— (sẽ hỏi lại Shopee API)'],
            ['Giá', $result['product']['discounted_price'] ?? '—'],
            ['Ảnh', $result['product']['product_image'] ?? '—'],
        ]);

        return self::SUCCESS;
    }
}
