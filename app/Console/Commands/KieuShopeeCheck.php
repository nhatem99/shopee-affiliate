<?php

namespace App\Console\Commands;

use App\Models\ApiConfig;
use App\Services\KieuShopeeService;
use App\Services\LaymaVoucherService;
use Illuminate\Console\Command;

/**
 * Gọi thử nguồn lấy mã từ chính server đang chạy.
 *
 * Điểm gãy hay gặp nhất của nguồn này là `next_action` — ID Server Action do bản build
 * Next.js của họ sinh ra, cứ deploy lại là đổi. Command này cho biết ngay "còn chạy không"
 * trong 1 lệnh, thay vì phải tự dựng curl với đủ header + multipart.
 *
 * --source=laymavoucher gọi nguồn dự phòng cùng nền tảng (xem LaymaVoucherService) — dùng để
 * chắc chắn nó chạy được TRƯỚC khi bật nó cho khách ở /admin/api-config.
 */
class KieuShopeeCheck extends Command
{
    protected $signature = 'kieushopee:check
        {url : Link sản phẩm Shopee dùng để thử}
        {--source=kieushopee : Nguồn cần gọi thử: kieushopee hoặc laymavoucher}';

    protected $description = 'Gọi thử nguồn lấy mã afp.ad (kieushopee / laymavoucher) từ server này và in kết quả';

    public function handle(): int
    {
        $service = match ($this->option('source')) {
            KieuShopeeService::SOURCE => app(KieuShopeeService::class),
            LaymaVoucherService::SOURCE => app(LaymaVoucherService::class),
            default => null,
        };

        if ($service === null) {
            $this->error('--source chỉ nhận kieushopee hoặc laymavoucher.');

            return self::INVALID;
        }

        // Cùng thứ tự ưu tiên với service: bản ghi admin trước, config sau.
        $endpoint = ApiConfig::where('platform', $service::SOURCE)->value('endpoint')
            ?: config('services.'.$service::SOURCE.'.endpoint');

        $this->line('Đang gọi '.$endpoint.'...');
        $this->newLine();

        $startedAt = microtime(true);
        $result = $service->fetchProductAndVoucherLink($this->argument('url'));
        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        if ($result === null) {
            $this->error("❌ Không lấy được link ({$durationMs}ms).");
            $this->line('Lý do cụ thể nằm ở storage/logs/laravel.log (tìm "'.class_basename($service).'").');
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
