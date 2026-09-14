<?php

namespace App\Listeners;

use App\Models\ScheduledTaskRun;
use App\Services\SchedulerService;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;

/**
 * Ghi mỗi lần cron chạy một job vào bảng scheduled_task_runs — bắt qua ba event có sẵn của
 * scheduler, nên thêm job mới vào routes/console.php là tự được ghi, không phải sửa gì ở đây.
 *
 * Chỉ ghi job dạng lệnh artisan; closure (nhịp tim scheduler) bỏ qua vì chạy mỗi phút, ghi ra
 * là 1.440 dòng/ngày toàn "ok" — và trạng thái của nó đã có chỗ riêng (Setting heartbeat).
 */
class ScheduledTaskRecorder
{
    /** @var array<int, ScheduledTaskRun> Dòng đang mở, khoá theo object task để nối starting → finished. */
    private array $open = [];

    public function starting(ScheduledTaskStarting $event): void
    {
        $command = SchedulerService::commandName($event->task);

        if ($command === null) {
            return;
        }

        $this->open[spl_object_id($event->task)] = ScheduledTaskRun::create([
            'command' => $command,
            'started_at' => now(),
            'triggered_by' => 'schedule',
        ]);
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $this->close($event->task, $event->task->exitCode ?? 0, $this->capturedOutput($event->task));
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->close($event->task, 1, $event->exception->getMessage());
    }

    private function close(Event $task, int $exitCode, ?string $output): void
    {
        $run = $this->open[spl_object_id($task)] ?? null;

        if ($run === null) {
            return;
        }

        unset($this->open[spl_object_id($task)]);

        $run->update([
            'finished_at' => now(),
            'exit_code' => $exitCode,
            'output' => ScheduledTaskRun::trimOutput($output),
        ]);
    }

    /**
     * Output của lệnh chỉ có khi job được khai `->storeOutput()` (scheduler ghi ra file trong
     * storage/logs); không thì $task->output là /dev/null và ở đây trả null.
     */
    private function capturedOutput(Event $task): ?string
    {
        $file = $task->output;

        if (! is_string($file) || ! str_starts_with($file, storage_path()) || ! is_file($file)) {
            return null;
        }

        return @file_get_contents($file) ?: null;
    }
}
