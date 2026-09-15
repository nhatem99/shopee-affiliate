<?php

namespace Tests\Feature;

use App\Models\ScheduledTaskRun;
use App\Models\UserActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trang cho admin chạy artisan từ web là thao tác mạnh nhất trong admin, nên phần lớn test ở
 * đây là về những gì KHÔNG được chạy.
 */
class AdminConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_regular_user_cannot_open_console(): void
    {
        $this->get('/admin/console')->assertRedirect('/login');
        $this->actingAs($this->createUser())->get('/admin/console')->assertForbidden();
        $this->actingAs($this->createUser())->post('/admin/console/run', ['command' => 'about'])->assertForbidden();
    }

    public function test_admin_sees_allowed_commands_including_app_commands(): void
    {
        $this->actingAs($this->createAdmin())
            ->get('/admin/console')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Console')
                ->where('commands', fn ($commands) => collect($commands)->contains('name', 'orders:notify-backfill')
                    && collect($commands)->contains('name', 'optimize:clear')
                    && ! collect($commands)->contains('name', 'tinker')
                    && ! collect($commands)->contains('name', 'migrate:fresh')));
    }

    public function test_admin_can_run_an_app_command_and_sees_output(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->post('/admin/console/run', ['command' => 'php artisan orders:notify-backfill'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $run = ScheduledTaskRun::latest('id')->first();

        $this->assertSame('orders:notify-backfill', $run->command);
        $this->assertSame(0, $run->exit_code);
        $this->assertSame("admin:{$admin->id}", $run->triggered_by);
        $this->assertStringContainsString('Sẽ gửi 0 thông báo', $run->output);

        $this->assertSame(1, UserActivity::where('event_type', 'artisan_run')->where('user_id', $admin->id)->count());
    }

    public function test_options_are_passed_through(): void
    {
        $this->actingAs($this->createAdmin())
            ->post('/admin/console/run', ['command' => 'schedule:list --timezone=Asia/Ho_Chi_Minh'])
            ->assertSessionHas('success');

        $this->assertSame('schedule:list --timezone=Asia/Ho_Chi_Minh', ScheduledTaskRun::latest('id')->first()->command);
    }

    public function test_dangerous_and_unknown_commands_are_refused(): void
    {
        $admin = $this->createAdmin();

        foreach (['tinker', 'migrate:fresh', 'db:wipe', 'down', 'migrate:rollback', 'khong-ton-tai'] as $command) {
            $this->actingAs($admin)
                ->post('/admin/console/run', ['command' => $command])
                ->assertSessionHasErrors('command');
        }

        $this->assertSame(0, ScheduledTaskRun::count());
        // Cả lệnh bị từ chối cũng phải để lại dấu vết.
        $this->assertSame(6, UserActivity::where('event_type', 'artisan_run')->count());
    }

    public function test_failed_command_is_recorded_with_exit_code(): void
    {
        $this->actingAs($this->createAdmin())
            ->post('/admin/console/run', ['command' => 'kieushopee:check'])
            ->assertSessionHas('error');

        $run = ScheduledTaskRun::latest('id')->first();

        $this->assertNotSame(0, $run->exit_code);
        $this->assertNotEmpty($run->output);
    }
}
