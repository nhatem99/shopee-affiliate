<?php

namespace Tests\Feature;

use App\Services\LogViewerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLogViewerTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = 'testing-log-viewer.log';

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('logs/'.self::FILE);
        file_put_contents($this->path, $this->sampleLog());
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    /** Log mẫu: 1 INFO, 1 ERROR có context JSON, 1 WARNING kèm stack trace nhiều dòng. */
    private function sampleLog(): string
    {
        return <<<'LOG'
        [2026-09-04 10:00:00] production.INFO: ShortLinkController: redirect thật khi bấm link {"code":"abc123"}
        [2026-09-04 10:01:00] production.ERROR: ChannelVoucherMinter: chuỗi chạy xong nhưng không ra mã {"channel":"fb","status":403,"body":"Forbidden"}
        [2026-09-04 10:02:00] production.WARNING: Có lỗi lạ xảy ra
        #0 /var/www/app/Services/Foo.php(12): bar()
        #1 {main}

        LOG;
    }

    // ── Phân quyền ────────────────────────────────────────────────────────────

    public function test_guest_cannot_access_logs(): void
    {
        $this->get('/admin/logs')->assertRedirect('/login');
    }

    public function test_regular_user_cannot_access_logs(): void
    {
        $this->actingAs($this->createUser())->get('/admin/logs')->assertForbidden();
    }

    // ── Đọc & bóc tách log ────────────────────────────────────────────────────

    public function test_admin_sees_entries_newest_first(): void
    {
        $this->actingAs($this->createAdmin())
            ->get('/admin/logs?file='.self::FILE.'&level=ALL')
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Logs')
                ->where('filters.file', self::FILE)
                ->where('total', 3)
                ->where('entries.0.level', 'WARNING')
                ->where('entries.1.level', 'ERROR')
                ->where('entries.2.level', 'INFO')
                ->where('entries.2.logged_at', '2026-09-04 10:00:00')
            );
    }

    public function test_context_json_is_split_out_of_the_message(): void
    {
        $this->actingAs($this->createAdmin())
            ->get('/admin/logs?file='.self::FILE.'&level=ERROR')
            ->assertInertia(fn ($page) => $page
                ->where('entries.0.message', 'ChannelVoucherMinter: chuỗi chạy xong nhưng không ra mã')
                // Context được in đẹp lại nên tìm theo mảnh, không so khớp cả chuỗi.
                ->where('entries.0.detail', fn (string $detail) => str_contains($detail, '"status": 403'))
            );
    }

    public function test_multiline_stack_trace_stays_with_its_entry(): void
    {
        $this->actingAs($this->createAdmin())
            ->get('/admin/logs?file='.self::FILE.'&level=WARNING')
            ->assertInertia(fn ($page) => $page
                ->where('entries.0.message', 'Có lỗi lạ xảy ra')
                ->where('entries.0.detail', fn (string $detail) => str_contains($detail, '#0 /var/www/app/Services/Foo.php(12): bar()')
                    && str_contains($detail, '#1 {main}'))
            );
    }

    // ── Bộ lọc ────────────────────────────────────────────────────────────────

    public function test_level_filter_hides_less_severe_entries(): void
    {
        $this->actingAs($this->createAdmin())
            ->get('/admin/logs?file='.self::FILE.'&level=ERROR')
            ->assertInertia(fn ($page) => $page
                ->where('total', 1)
                ->where('entries.0.level', 'ERROR')
                // counts luôn đếm toàn bộ cửa sổ log đã nạp, không phụ thuộc bộ lọc level.
                ->where('counts.INFO', 1)
            );
    }

    public function test_search_filter_matches_message_and_context(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get('/admin/logs?file='.self::FILE.'&level=ALL&q=ChannelVoucher')
            ->assertInertia(fn ($page) => $page->where('total', 1)->where('entries.0.level', 'ERROR'));

        // "Forbidden" chỉ nằm trong context JSON, không có trong nội dung log.
        $this->actingAs($admin)
            ->get('/admin/logs?file='.self::FILE.'&level=ALL&q=Forbidden')
            ->assertInertia(fn ($page) => $page->where('total', 1));
    }

    public function test_invalid_level_falls_back_to_default(): void
    {
        $this->actingAs($this->createAdmin())
            ->get('/admin/logs?file='.self::FILE.'&level=bogus')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.level', 'WARNING')->where('total', 2));
    }

    // ── An toàn đường dẫn ─────────────────────────────────────────────────────

    public function test_path_traversal_is_rejected(): void
    {
        $service = new LogViewerService;

        $this->assertNull($service->resolve('../../.env'));
        $this->assertNull($service->resolve('/etc/passwd'));
        $this->assertNull($service->resolve('khong-ton-tai.log'));
        $this->assertNotNull($service->resolve(self::FILE));
    }

    public function test_unknown_file_falls_back_to_the_newest_log(): void
    {
        $this->actingAs($this->createAdmin())
            ->get('/admin/logs?file=../../.env')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.file', fn (?string $file) => $file === null || str_ends_with($file, '.log'))
            );
    }
}
