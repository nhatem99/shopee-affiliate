<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\WelcomeBonusService;
use Illuminate\Console\Command;

/**
 * Cấp thưởng người mới HỒI TỐ cho tài khoản có sẵn — tính năng chỉ tự cấp lúc tạo tài khoản,
 * ai đăng ký trước ngày bật thì không có. Idempotent: WelcomeBonusService::grant bỏ qua user
 * đã có dòng WELCOME-{id}, nên chạy đi chạy lại không cộng đôi.
 */
class GrantWelcomeBonus extends Command
{
    protected $signature = 'welcome-bonus:grant
        {--email= : Chỉ cấp cho một tài khoản theo email}
        {--all : Cấp cho toàn bộ tài khoản khách chưa có thưởng}';

    protected $description = 'Cấp thưởng người mới cho tài khoản đăng ký trước khi bật tính năng';

    public function handle(WelcomeBonusService $service): int
    {
        if (! $service->enabled()) {
            $this->error('Thưởng người mới đang tắt (Admin → Cài đặt). Bật lên rồi chạy lại.');

            return self::FAILURE;
        }

        $email = $this->option('email');

        if (! $email && ! $this->option('all')) {
            $this->error('Cần --email=... hoặc --all.');

            return self::INVALID;
        }

        $query = User::query()->where('role', '!=', 'admin');

        if ($email) {
            $query->where('email', $email);
        }

        $granted = 0;
        $skipped = 0;

        foreach ($query->cursor() as $user) {
            if ($service->grant($user)) {
                $granted++;
                $this->line("  + {$user->email}");
            } else {
                $skipped++;
            }
        }

        if ($email && $granted + $skipped === 0) {
            $this->error("Không thấy tài khoản khách nào có email {$email}.");

            return self::FAILURE;
        }

        $this->info("Đã cấp: {$granted} · Bỏ qua (đã có thưởng): {$skipped}");

        return self::SUCCESS;
    }
}
