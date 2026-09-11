<?php

namespace Tests\Feature;

use App\Models\UserActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminActivityFilterExportTest extends TestCase
{
    use RefreshDatabase;

    private function log(string $ip, string $when, array $extra = []): UserActivity
    {
        $a = UserActivity::create(array_merge([
            'event_type' => 'short_link_click',
            'ip_address' => $ip,
            'user_agent' => 'Mozilla/5.0',
        ], $extra));

        $a->forceFill(['created_at' => $when, 'updated_at' => $when])->save();

        return $a;
    }

    public function test_guest_cannot_export_activities(): void
    {
        $this->get('/admin/activities/export')->assertRedirect('/login');
    }

    public function test_regular_user_cannot_export_activities(): void
    {
        $this->actingAs($this->createUser())->get('/admin/activities/export')->assertForbidden();
    }

    public function test_filter_by_exact_ip(): void
    {
        $this->log('113.161.20.5', '2026-09-01 10:00:00', ['voucher_code' => 'KEEP']);
        $this->log('8.8.8.8', '2026-09-01 10:00:00', ['voucher_code' => 'DROP']);

        $this->actingAs($this->createAdmin())
            ->get('/admin/activities?ip=113.161.20.5')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activities.total', 1)
                ->where('activities.data.0.voucher_code', 'KEEP')
                ->where('filters.ip', '113.161.20.5')
            );
    }

    public function test_filter_by_partial_ip_matches_range(): void
    {
        $this->log('113.161.20.5', '2026-09-01 10:00:00');
        $this->log('113.161.44.9', '2026-09-01 10:00:00');
        $this->log('8.8.8.8', '2026-09-01 10:00:00');

        $this->actingAs($this->createAdmin())
            ->get('/admin/activities?ip=113.161.')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('activities.total', 2));
    }

    public function test_filter_by_date_range_is_inclusive_of_both_ends(): void
    {
        $this->log('1.1.1.1', '2026-09-01 23:59:00');
        $this->log('1.1.1.1', '2026-09-03 08:00:00');
        $this->log('1.1.1.1', '2026-09-05 00:01:00');
        $this->log('1.1.1.1', '2026-09-06 09:00:00');

        $this->actingAs($this->createAdmin())
            ->get('/admin/activities?from=2026-09-01&to=2026-09-05')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activities.total', 3)
                ->where('filters.from', '2026-09-01')
                ->where('filters.to', '2026-09-05')
            );
    }

    public function test_invalid_date_is_ignored_instead_of_breaking_the_page(): void
    {
        $this->log('1.1.1.1', '2026-09-03 08:00:00');

        $this->actingAs($this->createAdmin())
            ->get('/admin/activities?from=2026-02-31&to=hom-nay')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activities.total', 1)
                ->missing('filters.from')
                ->missing('filters.to')
            );
    }

    public function test_daily_counts_split_page_views_from_all_events_and_zero_fill_missing_days(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $this->log('1.1.1.1', "$today 08:00:00", ['event_type' => 'page_view']);
        $this->log('1.1.1.1', "$today 08:01:00", ['event_type' => 'page_view']);
        $this->log('1.1.1.1', "$today 08:02:00", ['event_type' => 'voucher_copy']);
        $this->log('2.2.2.2', "$yesterday 12:00:00", ['event_type' => 'page_view']);
        // Quá 14 ngày → không được tính vào biểu đồ.
        $this->log('3.3.3.3', now()->subDays(20)->toDateTimeString(), ['event_type' => 'page_view']);

        $this->actingAs($this->createAdmin())
            ->get('/admin/activities')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('daily', 14)
                ->where('daily.13.date', $today)
                ->where('daily.13.page_views', 2)
                ->where('daily.13.events', 3)
                ->where('daily.12.date', $yesterday)
                ->where('daily.12.page_views', 1)
                ->where('daily.12.events', 1)
                ->where('daily.0.page_views', 0)
                ->where('daily.0.events', 0)
            );
    }

    public function test_admin_visits_are_not_tracked(): void
    {
        $this->actingAs($this->createAdmin())
            ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->get('/')
            ->assertOk();

        $this->assertDatabaseCount('user_activities', 0);

        $this->actingAs($this->createUser())
            ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->get('/')
            ->assertOk();

        $this->assertDatabaseHas('user_activities', ['event_type' => 'page_view']);
    }

    public function test_export_returns_csv_limited_to_the_active_filters(): void
    {
        $this->log('113.161.20.5', '2026-09-03 08:00:00', ['voucher_code' => 'INRANGE']);
        $this->log('113.161.20.5', '2026-09-20 08:00:00', ['voucher_code' => 'TOOLATE']);
        $this->log('8.8.8.8', '2026-09-03 08:00:00', ['voucher_code' => 'OTHERIP']);

        $response = $this->actingAs($this->createAdmin())
            ->get('/admin/activities/export?ip=113.161.20.5&from=2026-09-01&to=2026-09-05');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'hoat-dong_2026-09-01_den_2026-09-05.csv',
            $response->headers->get('Content-Disposition')
        );

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'CSV cần BOM để Excel đọc đúng tiếng Việt.');
        $this->assertStringContainsString('INRANGE', $csv);
        $this->assertStringNotContainsString('TOOLATE', $csv);
        $this->assertStringNotContainsString('OTHERIP', $csv);
    }
}
