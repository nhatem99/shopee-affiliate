<?php

namespace App\Console\Commands;

use App\Services\ChannelVoucher\BrowserLinkResolver;
use App\Services\ChannelVoucher\ChannelVoucherMinter;
use App\Services\ChannelVoucher\FacebookGraphClient;
use Illuminate\Console\Command;

/**
 * Chạy trọn chuỗi tự đúc mã trên MỘT sản phẩm và in ra từng mắt xích.
 *
 * Cơ chế này nối 3 hệ thống ngoài tầm kiểm soát (Graph API của Meta, Chromium, Shopee). Khi nó
 * không ra mã, câu hỏi luôn là "gãy ở đâu" — lệnh này trả lời trong một lần chạy, thay vì phải
 * đọc ngược /admin/logs và đoán. Cũng là cách kiểm chứng giả định gốc: liệu đăng link lên
 * Facebook rồi mở ra có thật sự khiến Shopee đúc mã hay không.
 */
class ChannelVoucherCheck extends Command
{
    protected $signature = 'voucher:mint-check
        {url : Link sản phẩm Shopee dùng để thử}
        {--channel=fb : Kênh cần đúc mã (fb hoặc ig)}
        {--manual : Chỉ comment rồi in link comment ra để tự mở trên điện thoại; không mở trình duyệt, không xoá comment}';

    protected $description = 'Chạy thử chuỗi tự đúc link có mã (comment Facebook/Instagram -> trình duyệt -> Shopee)';

    public function handle(
        ChannelVoucherMinter $minter,
        FacebookGraphClient $graph,
        BrowserLinkResolver $browser,
    ): int {
        $channel = (string) $this->option('channel');

        $this->line('Cấu hình');
        $this->line('  channel_voucher.enabled : '.(config('services.channel_voucher.enabled') ? 'bật' : 'TẮT (khách chưa dùng đường này)'));
        $this->line('  KOL pid                 : '.(config('services.shopee_affiliate.kol_pid') ?: '— chưa đặt'));
        $this->line('  đổi về mmp_pid          : '.config('services.shopee_affiliate.mmp_pid'));
        $this->newLine();

        // Hai thứ hỏng thầm lặng nhất: token Facebook hết hạn, và Chromium mất phiên đăng nhập.
        // Hỏi thẳng cả hai trước khi chạy chuỗi, để không đọc nhầm triệu chứng thành nguyên nhân.
        $token = (string) config($channel === 'ig'
            ? 'services.facebook.ig_access_token'
            : 'services.facebook.page_access_token');

        $identity = $token !== '' ? $graph->identity($token) : null;
        $this->line('Token Facebook          : '.($identity
            ? "OK — {$identity['name']} ({$identity['id']})"
            : 'HỎNG hoặc chưa đặt — xem mã lỗi ở /admin/logs'));

        $manual = (bool) $this->option('manual');

        // Chế độ --manual không đụng tới trình duyệt, nên đừng bắt người dùng đợi một lần gọi
        // health tới service chưa dựng rồi đọc một dòng báo lỗi không liên quan.
        if (! $manual) {
            $health = $browser->health();
            $this->line('Browser service         : '.match (true) {
                $health === null || ! $health['ok'] => 'KHÔNG gọi được — '.($health['detail'] ?? 'không rõ'),
                ! $health['logged_in'] => 'chạy nhưng CHƯA đăng nhập Facebook — chạy lại deploy/browser-resolver/login.mjs',
                default => 'OK, đã đăng nhập Facebook',
            });
        }
        $this->newLine();

        $this->line($manual
            ? "Chế độ THỦ CÔNG: chỉ comment lên '{$channel}' rồi in link ra, comment sẽ được GIỮ LẠI..."
            : "Đang chạy chuỗi đúc mã kênh '{$channel}'...");
        $this->newLine();

        $steps = $minter->run($this->argument('url'), $channel, keepComment: $manual, skipBrowser: $manual);

        $this->table(
            ['Bước', 'Kết quả', 'Thời gian', 'Chi tiết'],
            array_map(fn (array $s) => [
                $s['step'],
                $s['ok'] ? '✅ OK' : '❌ Hỏng',
                $s['duration_ms'] ? $s['duration_ms'].'ms' : '—',
                mb_strimwidth($s['detail'], 0, 110, '…'),
            ], $steps),
        );

        $last = end($steps);

        if ($manual) {
            return $this->reportManual($steps, $last);
        }

        if (! $last || ! $last['ok'] || ! isset($last['url'])) {
            $this->error('Không đúc được link có mã — bước hỏng đầu tiên ở trên là chỗ cần sửa.');

            return self::FAILURE;
        }

        $this->info('Đúc thành công. Link CÓ mã (vẫn mang mmp_pid của KOL):');
        $this->line('  '.$last['url']);
        $this->newLine();
        $this->line('Khi khách bấm mua, AffiliateLinkRewriterService sẽ đổi mmp_pid sang '
            .config('services.shopee_affiliate.mmp_pid').' — chữ ký giữ nguyên nên mã vẫn áp.');

        return self::SUCCESS;
    }

    /**
     * Kết quả của chế độ --manual: in ra link comment để tự mở trên điện thoại, kèm đúng những
     * việc cần làm tiếp. Mục đích là kiểm chứng giả định gốc — "đăng link lên Facebook rồi mở ra
     * thì Shopee đúc mã" — bằng mắt người, trước khi tin vào chuỗi tự động.
     *
     * @param  list<array{step: string, ok: bool, detail: string, duration_ms: int}>  $steps
     */
    private function reportManual(array $steps, array|false $last): int
    {
        if (! $last || ! $last['ok'] || $last['step'] !== 'lấy permalink') {
            $this->error('Chưa comment được — bước hỏng đầu tiên ở trên là chỗ cần sửa.');

            return self::FAILURE;
        }

        $this->info('Đã comment xong. COMMENT VẪN CÒN TRÊN BÀI ĐĂNG (nhớ tự xoá sau khi thử).');
        $this->newLine();
        $this->line('Mở link này trên app Facebook ở điện thoại:');
        $this->line('  '.$last['detail']);
        $this->newLine();
        $this->line('Rồi bấm vào link Shopee trong comment và kiểm tra 2 thứ:');
        $this->line('  1. Trang sản phẩm có hiện voucher độc quyền của kênh không (nhìn bằng mắt).');
        $this->line('  2. URL cuối cùng: bấm ··· → Copy link, xem có credential_token không.');
        $this->newLine();
        $this->line('Có credential_token = giả định đúng, chuỗi tự động chạy được.');
        $this->line('Không có = Shopee không đúc mã theo đường này, phải nhắm tự động hoá chỗ khác.');

        return self::SUCCESS;
    }
}
