<?php

namespace Tests\Feature;

use App\Listeners\ScheduledTaskRecorder;
use App\Models\ScheduledTaskRun;
use App\Models\Setting;
use App\Services\SchedulerService;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * /admin/scheduler: admin phải thấy được cron có chạy thật không, job chạy ra sao, và bấm chạy
 * ngay được — không cần SSH. Production từng thiếu cron nhiều tuần mà không ai hay, vì Schedule
 * chỉ im lặng không chạy.
 */
class AdminSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_cannot_access_scheduler(): void
    {
        $this->actingAs($this->createUser())->get('/admin/scheduler')->assertForbidden();
    }

    public function test_status_is_never_when_no_heartbeat_was_recorded(): void
    {
        $status = app(SchedulerService::class)->status();

        $this->assertSame('never', $status['state']);
        $this->assertStringContainsString('schedule:run', $status['cron_line']);
    }

    public function test_status_is_running_with_a_fresh_heartbeat_and_stale_when_old(): void
    {
        Setting::set(SchedulerService::HEARTBEAT_KEY, now()->subMinute()->toIso8601String());
        $this->assertSame('running', app(SchedulerService::class)->status()['state']);

        Setting::set(SchedulerService::HEARTBEAT_KEY, now()->subMinutes(10)->toIso8601String());
        $this->assertSame('stale', app(SchedulerService::class)->status()['state']);
    }

    /** Lịch khai ở routes/console.php phải đọc được từ HTTP (file đó vốn chỉ nạp ở console). */
    public function test_page_lists_scheduled_artisan_commands_but_not_the_heartbeat_closure(): void
    {
        $this->actingAs($this->createAdmin())
            ->get('/admin/scheduler')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Scheduler')
                ->has('status.state')
                ->where('tasks', fn ($tasks) => collect($tasks)->pluck('command')->contains('facebook:sync-reels')
                    && collect($tasks)->pluck('command')->contains('model:prune')
                    && ! collect($tasks)->pluck('description')->contains('scheduler-heartbeat'))
                ->has('runs')
                ->has('reelSlots')
            );
    }

    public function test_run_now_executes_the_command_and_records_the_run(): void
    {
        Http::fake();
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->post('/admin/scheduler/run', ['command' => 'facebook:sync-reels'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $run = ScheduledTaskRun::first();
        $this->assertSame('facebook:sync-reels', $run->command);
        $this->assertSame(0, $run->exit_code);
        $this->assertSame("admin:{$admin->id}", $run->triggered_by);
        $this->assertNotNull($run->finished_at);
        // Lệnh in ra dòng "chưa bật cấu hình" — output phải được giữ lại để admin đọc.
        $this->assertStringContainsString('Chưa bật', $run->output);
    }

    /** Không phải cửa chạy artisan tuỳ ý — lệnh ngoài lịch bị từ chối và không được ghi. */
    public function test_run_now_rejects_commands_that_are_not_scheduled(): void
    {
        $this->actingAs($this->createAdmin())
            ->post('/admin/scheduler/run', ['command' => 'migrate:fresh'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('scheduled_task_runs', 0);
    }

    /** Cron chạy job → listener ghi một dòng, nối đúng starting với finished. */
    public function test_recorder_writes_a_row_per_scheduled_command_run(): void
    {
        $event = app(Schedule::class)->command('facebook:sync-reels');
        $event->exitCode = 0;
        $recorder = new ScheduledTaskRecorder;

        $recorder->starting(new ScheduledTaskStarting($event));
        $this->assertTrue(ScheduledTaskRun::first()->isRunning());

        $recorder->finished(new ScheduledTaskFinished($event, 1.5));

        $run = ScheduledTaskRun::first();
        $this->assertSame('facebook:sync-reels', $run->command);
        $this->assertSame('schedule', $run->triggered_by);
        $this->assertTrue($run->succeeded());
    }

    public function test_recorder_ignores_closure_tasks(): void
    {
        $event = app(Schedule::class)->call(fn () => null)->name('scheduler-heartbeat');

        (new ScheduledTaskRecorder)->starting(new ScheduledTaskStarting($event));

        $this->assertDatabaseCount('scheduled_task_runs', 0);
    }
}
