<?php

namespace Tests\Feature;

use App\Exceptions\AffiliateScanException;
use App\Models\AffiliateLink;
use App\Services\AffiliateScanOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AffiliateScanTest extends TestCase
{
    use RefreshDatabase;

    private function mockOrchestrator(array $result): void
    {
        $mock = Mockery::mock(AffiliateScanOrchestrator::class);
        $mock->shouldReceive('scan')->once()->andReturn($result);
        $this->app->instance(AffiliateScanOrchestrator::class, $mock);
    }

    private function mockOrchestratorThrows(string $message): void
    {
        $mock = Mockery::mock(AffiliateScanOrchestrator::class);
        $mock->shouldReceive('scan')->once()->andThrow(new AffiliateScanException($message));
        $this->app->instance(AffiliateScanOrchestrator::class, $mock);
    }

    private function fakeScanResult(): array
    {
        return [
            'product' => [
                'name'             => 'Áo thun Shopee',
                'image'            => null,
                'platform'         => 'shopee',
                'original_price'   => 200000,
                'discounted_price' => 150000,
                'discount_percent' => 25,
                'rating'           => 4.8,
                'sold_count'       => 1000,
            ],
            'vouchers'      => [],
            'affiliateLink' => 'https://at.link/abc123',
            'cashback'      => 7500,
            'savings'       => [
                'original'         => 200000,
                'product_discount' => 50000,
                'voucher_discount' => 0,
                'final_price'      => 150000,
                'total_saved'      => 50000,
                'pct_saved'        => 25,
            ],
        ];
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_scan_requires_url(): void
    {
        $this->post('/affiliate/scan', [])->assertSessionHasErrors('url');
    }

    public function test_scan_rejects_invalid_url_format(): void
    {
        $this->post('/affiliate/scan', ['url' => 'not-a-url'])
            ->assertSessionHasErrors('url');
    }

    public function test_scan_rejects_url_exceeding_max_length(): void
    {
        $this->post('/affiliate/scan', ['url' => 'https://shopee.vn/' . str_repeat('a', 2000)])
            ->assertSessionHasErrors('url');
    }

    // ── Scan success ─────────────────────────────────────────────────────────

    public function test_guest_can_scan_url_and_gets_result_page(): void
    {
        $this->mockOrchestrator($this->fakeScanResult());

        $response = $this->post('/affiliate/scan', [
            'url' => 'https://shopee.vn/product-i.123456.789',
        ]);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Result')
            ->has('product')
            ->has('affiliateLink')
        );
    }

    public function test_authenticated_user_can_scan_url(): void
    {
        $user = $this->createUser();
        $this->mockOrchestrator($this->fakeScanResult());

        $response = $this->actingAs($user)->post('/affiliate/scan', [
            'url' => 'https://shopee.vn/product-i.123456.789',
        ]);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Result'));
    }

    public function test_scan_stores_affiliate_link_in_database(): void
    {
        $user = $this->createUser();
        $this->mockOrchestrator($this->fakeScanResult());

        $this->actingAs($user)->post('/affiliate/scan', [
            'url' => 'https://shopee.vn/product-i.123456.789',
        ]);

        $this->assertDatabaseHas('affiliate_links', [
            'user_id'  => $user->id,
            'platform' => 'shopee',
        ]);
    }

    // ── Scan failure ─────────────────────────────────────────────────────────

    public function test_scan_redirects_back_with_error_on_unsupported_platform(): void
    {
        $this->mockOrchestratorThrows('Chỉ hỗ trợ link từ Shopee, Lazada, Tiki và TikTok Shop.');

        $this->post('/affiliate/scan', ['url' => 'https://shopee.vn/product-i.1.1'])
            ->assertRedirect()
            ->assertSessionHasErrors('url');
    }

    public function test_scan_error_message_is_passed_to_session(): void
    {
        $errorMessage = 'URL không hợp lệ.';
        $this->mockOrchestratorThrows($errorMessage);

        $response = $this->post('/affiliate/scan', ['url' => 'https://shopee.vn/test']);
        $response->assertSessionHasErrors(['url' => $errorMessage]);
    }

    // ── History ───────────────────────────────────────────────────────────────

    public function test_history_requires_authentication(): void
    {
        $this->get('/history')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_history(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)
            ->get('/history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('History')
                ->has('links')
            );
    }

    public function test_history_only_shows_current_users_links(): void
    {
        $user  = $this->createUser();
        $other = $this->createUser();

        AffiliateLink::create([
            'user_id'      => $other->id,
            'original_url' => 'https://shopee.vn/other',
            'short_url'    => 'https://at.link/other',
            'platform'     => 'shopee',
            'product_name' => 'Other product',
        ]);

        AffiliateLink::create([
            'user_id'      => $user->id,
            'original_url' => 'https://shopee.vn/mine',
            'short_url'    => 'https://at.link/mine',
            'platform'     => 'shopee',
            'product_name' => 'My product',
        ]);

        $this->actingAs($user)
            ->get('/history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('History')
                ->where('links.total', 1)
            );
    }
}
