<?php

namespace Tests\Feature;

use App\Services\SalesOcService;
use App\Services\ZaloOaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Tests for ZaloWebhookController — covers the group-chat (GMF) feature added
 * alongside the pre-existing 1-1 direct-message handler.
 *
 * Design notes:
 *  - Real ZaloOaService::verifyWebhookSignature() runs for all tests — we only
 *    intercept sendGroupText() via makePartial() to prevent real HTTP calls.
 *  - SalesOcService is mocked via the IoC container for tests that reach the
 *    inner handler; it makes external HTTP calls we must not fire in tests.
 *  - Shopee.vn URLs (not shp.ee) are used in payloads so that
 *    ShopeeLinkResolverService and AffiliateLinkRewriterService both return
 *    immediately without making any HTTP requests.
 *  - Cache::flush() in setUp() ensures dedup state never leaks between tests.
 */
class ZaloWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = 'test_zalo_app_id_123';

    private const SECRET_KEY = 'test_zalo_secret_key_abc';

    private const OA_TOKEN = 'test_oa_token_xyz';

    private const ENDPOINT = '/api/zalo/webhook';

    protected function setUp(): void
    {
        parent::setUp();

        // Cache uses the array driver in tests (phpunit.xml: CACHE_STORE=array).
        // The same store instance persists across test methods within a process,
        // so we must flush it to prevent the dedup key from one test leaking.
        Cache::flush();

        config([
            'services.zalo.app_id' => self::APP_ID,
            'services.zalo.secret_key' => self::SECRET_KEY,
            'services.zalo.oa_token' => self::OA_TOKEN,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Compute the X-ZEvent-Signature value for a known raw body + timestamp,
     * replicating the formula in ZaloOaService::verifyWebhookSignature().
     * Formula: sha256( app_id . rawBody . timestamp . secret_key )
     */
    private function signature(string $rawBody, string $timestamp): string
    {
        return hash('sha256', self::APP_ID.$rawBody.$timestamp.self::SECRET_KEY);
    }

    /**
     * POST the webhook with a correctly computed signature.
     *
     * IMPORTANT: postJson() calls json_encode($payload) internally and sends
     * that as the raw body. We compute our own json_encode($payload) first,
     * derive the signature from it, then pass the same $payload to postJson()
     * so that the bytes match exactly what the controller reads via getContent().
     */
    private function postWithValidSignature(array $payload, array $extraHeaders = []): TestResponse
    {
        $rawBody = json_encode($payload);
        $timestamp = (string) ($payload['timestamp'] ?? '0');
        $sig = $this->signature($rawBody, $timestamp);

        return $this->postJson(self::ENDPOINT, $payload, array_merge(
            ['X-ZEvent-Signature' => 'mac='.$sig],
            $extraHeaders
        ));
    }

    /** Canonical group-message payload. Override any top-level key via $overrides. */
    private function groupPayload(array $overrides = []): array
    {
        return array_merge([
            'event_name' => 'user_send_group_text',
            'timestamp' => '1700000000',
            'message' => [
                'text' => 'Xem sản phẩm này nha https://shopee.vn/product-i.123456789.987654321',
                'msg_id' => 'msg_default_001',
            ],
            'recipient' => [
                'id' => 'group_001',
            ],
        ], $overrides);
    }

    /**
     * Fake data shaped like what SalesOcService::fetchProductAndVoucherLabels() returns.
     * Uses shopee.vn URLs so AffiliateLinkRewriterService resolves them without HTTP.
     */
    private function fakeSalesOcData(): array
    {
        return [
            'product_name' => 'Áo Thun Test',
            'product_image' => null,
            'original_price' => 200000.0,
            'discounted_price' => 150000.0,
            'discount_percent' => 25,
            'sold_count' => 1000,
            'rating' => 4.8,
            'voucher_labels' => ['Giảm 50k'],
            'voucher_links' => [
                'facebook' => [
                    ['label' => 'Giảm 50k', 'url' => 'https://shopee.vn/product?mmp_pid=salesoc_fb&smtt=0'],
                ],
                'youtube' => [],
                'instagram' => [],
                'zalo' => [],
            ],
        ];
    }

    /**
     * Bind a Mockery partial mock of ZaloOaService.
     * verifyWebhookSignature() continues to call the real implementation;
     * sendGroupText() is intercepted and asserted for call count.
     */
    private function mockZaloService(int $expectedSendGroupCalls = 0): void
    {
        $mock = Mockery::mock(ZaloOaService::class)->makePartial();
        $mock->shouldReceive('sendGroupText')
            ->times($expectedSendGroupCalls)
            ->andReturn(true);
        $this->app->instance(ZaloOaService::class, $mock);
    }

    /** Bind a mock SalesOcService that returns the given value for any call. */
    private function mockSalesOcService(?array $returnValue): void
    {
        $mock = Mockery::mock(SalesOcService::class);
        $mock->shouldReceive('fetchProductAndVoucherLabels')
            ->andReturn($returnValue);
        $this->app->instance(SalesOcService::class, $mock);
    }

    // ── Test 1a: Missing X-ZEvent-Signature header → 403 ─────────────────────

    public function test_missing_signature_header_returns_403(): void
    {
        $response = $this->postJson(self::ENDPOINT, $this->groupPayload());

        $response->assertStatus(403)
            ->assertJson(['ok' => false]);
    }

    // ── Test 1b: Corrupted / wrong signature → 403 ───────────────────────────

    public function test_invalid_signature_value_returns_403(): void
    {
        $response = $this->postJson(self::ENDPOINT, $this->groupPayload(), [
            'X-ZEvent-Signature' => 'mac='.str_repeat('0', 64),
        ]);

        $response->assertStatus(403)
            ->assertJson(['ok' => false]);
    }

    // ── Test 2: Unconfigured credentials → 403 even with a header present ─────

    /**
     * The controller is "fail-closed": if ZALO_APP_ID / ZALO_OA_SECRET_KEY are
     * not set, every request is rejected regardless of what signature is sent.
     */
    public function test_unconfigured_app_id_and_secret_key_return_403(): void
    {
        config([
            'services.zalo.app_id' => null,
            'services.zalo.secret_key' => null,
        ]);

        // Send a request with *some* signature header — should still be rejected.
        $response = $this->postJson(self::ENDPOINT, $this->groupPayload(), [
            'X-ZEvent-Signature' => 'mac=anysignaturevalue',
        ]);

        $response->assertStatus(403)
            ->assertJson(['ok' => false]);
    }

    // ── Test 3: Valid signature + shopee URL → exactly one group reply sent ───

    public function test_valid_group_message_with_shopee_url_triggers_one_group_reply(): void
    {
        $this->mockZaloService(expectedSendGroupCalls: 1);
        $this->mockSalesOcService($this->fakeSalesOcData());

        $response = $this->postWithValidSignature($this->groupPayload());

        $response->assertOk()
            ->assertJson(['ok' => true]);
    }

    // ── Test 4: Same msg_id sent twice → only one group-send across both ──────

    /**
     * Simulates a Zalo webhook retry with the same msg_id.
     * Cache::add() keyed on the msg_id prevents the second request from
     * triggering another sendGroupText() call (dedup logic in handleGroupMessage).
     */
    public function test_duplicate_msg_id_only_triggers_one_group_send_across_two_requests(): void
    {
        $this->mockZaloService(expectedSendGroupCalls: 1);
        $this->mockSalesOcService($this->fakeSalesOcData());

        $payload = $this->groupPayload([
            'message' => [
                'text' => 'https://shopee.vn/product-i.111.222 mua đi',
                'msg_id' => 'dedup_msg_unique_999',
            ],
        ]);

        // First delivery — processed normally.
        $this->postWithValidSignature($payload)->assertOk();

        // Zalo retry with identical msg_id — must be silently swallowed.
        $this->postWithValidSignature($payload)->assertOk();

        // Mockery verifies times(1) on tearDown — if sendGroupText were called
        // twice the assertion would fail there, not here.
    }

    // ── Test 5: No Shopee URL in text → no group send, response ok:true ───────

    public function test_group_message_without_shopee_url_returns_ok_without_sending(): void
    {
        $this->mockZaloService(expectedSendGroupCalls: 0);
        // SalesOcService must never be called either; bind a strict mock.
        $strictSalesOc = Mockery::mock(SalesOcService::class);
        $strictSalesOc->shouldNotReceive('fetchProductAndVoucherLabels');
        $this->app->instance(SalesOcService::class, $strictSalesOc);

        $payload = $this->groupPayload([
            'message' => [
                'text' => 'Xin chào nhóm! Không có link Shopee nào trong tin nhắn này.',
                'msg_id' => 'msg_no_url_001',
            ],
        ]);

        $response = $this->postWithValidSignature($payload);

        $response->assertOk()
            ->assertJson(['ok' => true]);
    }

    // ── Test 6: Unknown event_name → ok:true, no send ────────────────────────

    /**
     * The match() in handle() has a default arm that returns ok:true for any
     * event_name that isn't 'user_send_text' or 'user_send_group_text'.
     */
    public function test_unknown_event_name_returns_ok_without_any_send(): void
    {
        $this->mockZaloService(expectedSendGroupCalls: 0);

        $payload = $this->groupPayload(['event_name' => 'oa_send_text']);

        $response = $this->postWithValidSignature($payload);

        $response->assertOk()
            ->assertJson(['ok' => true]);
    }
}
