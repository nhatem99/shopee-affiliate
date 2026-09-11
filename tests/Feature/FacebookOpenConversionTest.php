<?php

namespace Tests\Feature;

use App\Models\UserActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacebookOpenConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_log_facebook_open_with_mode_and_product(): void
    {
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->postJson('/track/event', [
                'event_type' => 'facebook_open',
                'product_name' => 'Áo thun nam',
                'platform' => 'shopee',
                'source' => 'fb_reel',
                'url' => 'https://www.facebook.com/123/posts/456',
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('user_activities', [
            'event_type' => 'facebook_open',
            'product_name' => 'Áo thun nam',
            'source' => 'fb_reel',
            'url' => 'https://www.facebook.com/123/posts/456',
        ]);
    }

    public function test_unknown_event_type_is_rejected(): void
    {
        // App chỉ render JSON cho api/*, nên lỗi validate ở đây là redirect kèm errors.
        $this->from('/')
            ->post('/track/event', ['event_type' => 'anything_else'])
            ->assertRedirect('/')
            ->assertSessionHasErrors('event_type');

        $this->assertDatabaseCount('user_activities', 0);
    }

    public function test_admin_summary_counts_conversions_by_mode_and_product(): void
    {
        $log = fn (array $extra) => UserActivity::create(array_merge([
            'event_type' => 'facebook_open',
            'ip_address' => '1.1.1.1',
            'user_agent' => 'Mozilla/5.0',
        ], $extra));

        $log(['source' => 'fb_comment', 'product_name' => 'Áo thun']);
        $log(['source' => 'fb_comment', 'product_name' => 'Áo thun']);
        $log(['source' => 'fb_reel', 'product_name' => 'Quần jean']);
        // Không phải chuyển đổi → không được tính.
        $log(['event_type' => 'page_view', 'source' => null, 'product_name' => 'Áo thun']);

        $this->actingAs($this->createAdmin())
            ->get('/admin/activities')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.conversions.total', 3)
                ->where('summary.conversions.by_mode.fb_comment', 2)
                ->where('summary.conversions.by_mode.fb_reel', 1)
                ->where('summary.conversions.top_products.Áo thun', 2)
                ->where('summary.conversions.top_products.Quần jean', 1)
            );
    }
}
