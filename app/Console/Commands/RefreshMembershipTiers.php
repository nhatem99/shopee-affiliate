<?php

namespace App\Console\Commands;

use App\Services\MembershipTierService;
use Illuminate\Console\Command;

/**
 * Xét lại hạng thành viên của toàn bộ khách theo tiền hoàn đã duyệt của QUÝ TRƯỚC.
 *
 * Vì sao chạy HÀNG NGÀY trong khi hạng chỉ đổi vào ngày đầu quý (1/1, 1/4, 1/7, 1/10):
 * hoa hồng của quý trước không nằm sẵn trong DB vào lúc 0h ngày đầu quý — tiền chỉ vào ví khi
 * admin nhập báo cáo Shopee, thường trễ vài ngày tới vài tuần. Chạy đúng một lượt lúc giao quý
 * là chốt hạng trên một quý chưa đối soát xong, và khách bị giam ở hạng thấp suốt 3 tháng vì
 * một cái hẹn giờ. Chạy lại mỗi ngày thì hạng tự sửa khi số liệu về đủ; còn với khách thì hạng
 * vẫn chỉ đổi khi sang quý mới, vì tổng của quý trước là một con số đã đóng.
 */
class RefreshMembershipTiers extends Command
{
    protected $signature = 'tiers:refresh';

    protected $description = 'Xét hạng thành viên theo tiền hoàn đã duyệt của quý trước';

    public function handle(MembershipTierService $tiers): int
    {
        if (! $tiers->enabled()) {
            $this->info('Hạng thành viên đang tắt (hoặc tỉ lệ hoàn tiền = 0) — không xét ai.');

            return self::SUCCESS;
        }

        $summary = $tiers->refreshAll();

        $this->info(sprintf(
            'Quý %s: đã xét %d tài khoản, đổi hạng %d.',
            $summary['quarter'],
            $summary['checked'],
            $summary['updated'],
        ));

        return self::SUCCESS;
    }
}
