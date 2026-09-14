<?php

namespace App\Services;

use App\Models\FacebookReelSlot;
use App\Models\ScheduledTaskRun;
use App\Models\Setting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;

/**
 * Dữ liệu cho màn hình /admin/scheduler: scheduler có đang chạy không, từng job chạy ra sao,
 * và cho admin bấm chạy ngay một job mà không cần SSH.
 *
 * Nguồn sự thật về "có đang chạy không" là NHỊP TIM: một closure trong routes/console.php ghi
 * giờ vào Setting mỗi phút. Không có cron `schedule:run` trên server thì nhịp tim không bao giờ
 * được ghi — đó chính xác là tình huống production đã gặp (cron chưa được thêm suốt nhiều tuần
 * mà không ai biết, vì mọi Schedule chỉ im lặng không chạy).
 */
class SchedulerService
{
    public const HEARTBEAT_KEY = 'scheduler_heartbeat';

    /** Quá số phút này không có nhịp tim thì coi là scheduler đã ngừng (cron chạy mỗi phút). */
    public const STALE_AFTER_MINUTES = 3;

    /** @return array{state: string, last_heartbeat: ?string, minutes_ago: ?int, cron_line: string} */
    public function status(): array
    {
        $raw = Setting::get(self::HEARTBEAT_KEY);
        $last = $raw ? Carbon::parse($raw) : null;
        $minutesAgo = $last ? (int) $last->diffInMinutes(now()) : null;

        return [
            'state' => match (true) {
                $last === null => 'never',
                $minutesAgo > self::STALE_AFTER_MINUTES => 'stale',
                default => 'running',
            },
            'last_heartbeat' => $last?->toDateTimeString(),
            'last_heartbeat_human' => self::ago($last),
            'minutes_ago' => $minutesAgo,
            // Dòng cần dán vào `crontab -e` trên server — hiện sẵn để admin copy, khỏi phải tra.
            'cron_line' => '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
        ];
    }

    /**
     * Danh sách job lệnh artisan đã đăng ký trong routes/console.php kèm lần chạy cuối.
     *
     * @return list<array{command: string, description: string, expression: string, next_run: string, last_run: ?array}>
     */
    public function tasks(): array
    {
        $tasks = [];

        foreach ($this->schedule()->events() as $event) {
            $command = self::commandName($event);

            if ($command === null) {
                continue;
            }

            $tasks[] = [
                'command' => $command,
                'description' => $event->description ?: $command,
                'expression' => $event->expression,
                'next_run' => Carbon::instance($event->nextRunDate())->toDateTimeString(),
                'last_run' => $this->present(
                    ScheduledTaskRun::where('command', $command)->latest('started_at')->first(),
                ),
            ];
        }

        return $tasks;
    }

    /**
     * Lịch sử gần nhất của mọi job, mới nhất lên đầu.
     *
     * @return list<array>
     */
    public function recentRuns(int $limit = 30): array
    {
        return ScheduledTaskRun::latest('started_at')->limit($limit)->get()
            ->map(fn (ScheduledTaskRun $run) => $this->present($run))
            ->all();
    }

    /**
     * Chạy ngay một job theo yêu cầu của admin, ghi lại y như cron chạy.
     *
     * CHỈ nhận lệnh đang có trong lịch — không phải cửa chạy artisan tuỳ ý từ web. Ai chiếm được
     * tài khoản admin cũng chỉ kích hoạt được đúng những job vốn dĩ tự chạy.
     */
    public function runNow(string $command, int $adminId): ScheduledTaskRun
    {
        $allowed = array_column($this->tasks(), 'command');

        if (! in_array($command, $allowed, true)) {
            throw new InvalidArgumentException("Lệnh {$command} không có trong lịch chạy.");
        }

        $run = ScheduledTaskRun::create([
            'command' => $command,
            'started_at' => now(),
            'triggered_by' => "admin:{$adminId}",
        ]);

        try {
            $exitCode = Artisan::call($command);
            $output = Artisan::output();
        } catch (\Throwable $e) {
            $exitCode = 1;
            $output = get_class($e).': '.$e->getMessage();
        }

        $run->update([
            'finished_at' => now(),
            'exit_code' => $exitCode,
            'output' => ScheduledTaskRun::trimOutput($output),
        ]);

        return $run;
    }

    /**
     * Trạng thái từng reel trong nhóm — lý do đầu tiên cần scheduler chạy đúng (job đối soát
     * caption, xem FacebookReelSyncService).
     *
     * @return list<array>
     */
    public function reelSlots(): array
    {
        return FacebookReelSlot::orderBy('reel_id')->get()->map(fn (FacebookReelSlot $slot) => [
            'reel_id' => $slot->reel_id,
            'url' => $slot->url(),
            'product_key' => $slot->product_key,
            'product_name' => $slot->product_name,
            'target_url' => $slot->target_url,
            'leased' => $slot->isLeased(),
            'leased_until' => $slot->leased_until?->toDateTimeString(),
            'synced_at' => $slot->synced_at?->toDateTimeString(),
            'synced_human' => self::ago($slot->synced_at),
            'sync_error' => $slot->sync_error,
        ])->all();
    }

    /**
     * Tên lệnh artisan của một job trong lịch ("facebook:sync-reels"), hoặc null nếu job là
     * closure. Scheduler lưu cả dòng shell (`'/usr/bin/php' 'artisan' facebook:sync-reels`;
     * Windows dùng nháy kép), nên phải bóc phần sau "artisan".
     */
    public static function commandName(Event $event): ?string
    {
        if (! is_string($event->command) || ! preg_match("#artisan['\"]?\s+(\S+)#", $event->command, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * "7 phút trước" tính ở SERVER, theo múi giờ của app. Tính ở trình duyệt thì máy admin đặt
     * múi giờ khác (vd +09) sẽ ra "2 giờ trước" cho một nhịp tim vừa ghi 5 phút trước.
     */
    private static function ago(?Carbon $at): ?string
    {
        return $at?->locale('vi')->diffForHumans();
    }

    /** Lịch được khai trong routes/console.php — file đó chỉ tự nạp ở console, HTTP phải gọi nạp. */
    private function schedule(): Schedule
    {
        app(ConsoleKernel::class)->bootstrap();

        return app(Schedule::class);
    }

    private function present(?ScheduledTaskRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'id' => $run->id,
            'command' => $run->command,
            'started_at' => $run->started_at->toDateTimeString(),
            'started_human' => self::ago($run->started_at),
            'finished_at' => $run->finished_at?->toDateTimeString(),
            'duration_ms' => $run->finished_at ? (int) $run->started_at->diffInMilliseconds($run->finished_at) : null,
            'exit_code' => $run->exit_code,
            'status' => $run->isRunning() ? 'running' : ($run->succeeded() ? 'ok' : 'failed'),
            'output' => $run->output,
            'triggered_by' => $run->triggered_by,
        ];
    }
}
