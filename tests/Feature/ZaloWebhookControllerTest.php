<?php

namespace Tests\Feature;

use App\Jobs\ReplyZaloGroupWithVouchers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Tests cho ZaloWebhookController — nhánh trả lời nhóm (GMF) bên cạnh handler tin nhắn 1-1.
 *
 * Ghi chú thiết kế:
 *  - ZaloOaService::verifyWebhookSignature() chạy thật ở mọi test; chỉ có việc gửi tin là bị chặn.
 *  - Việc nặng (đúc mã + gửi tin vào nhóm) đã chuyển sang ReplyZaloGroupWithVouchers, nên ở đây
 *    chỉ cần Queue::fake() và kiểm tra job có được đẩy đi đúng số lần không — không cần mock
 *    dịch vụ ngoài nào nữa.
 *  - Dùng URL shopee.vn (không phải shp.ee) để ShopeeLinkResolverService trả về ngay, không gọi HTTP.
 *  - Cache::flush() ở setUp() để trạng thái chống trùng không rò rỉ giữa các test.
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

        // Cache dùng driver array trong test (phpunit.xml: CACHE_STORE=array). Cùng một instance
        // sống qua nhiều test trong một process, nên phải xoá để key chống trùng không rò rỉ.
        Cache::flush();
        Queue::fake();

        config([
            'services.zalo.app_id' => self::APP_ID,
            'services.zalo.secret_key' => self::SECRET_KEY,
            'services.zalo.oa_token' => self::OA_TOKEN,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Tính X-ZEvent-Signature cho một body + timestamp đã biết, theo đúng công thức trong
     * ZaloOaService::verifyWebhookSignature(): sha256( app_id . rawBody . timestamp . secret_key )
     */
    private function signature(string $rawBody, string $timestamp): string
    {
        return hash('sha256', self::APP_ID.$rawBody.$timestamp.self::SECRET_KEY);
    }

    /**
     * QUAN TRỌNG: postJson() tự json_encode($payload) rồi gửi đi. Phải json_encode trước, ký trên
     * chuỗi đó, rồi truyền chính $payload vào postJson() để byte gửi đi khớp với byte đã ký.
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

    // ── Xác thực chữ ký ───────────────────────────────────────────────────────

    public function test_missing_signature_header_returns_403(): void
    {
        $this->postJson(self::ENDPOINT, $this->groupPayload())
            ->assertStatus(403)
            ->assertJson(['ok' => false]);
    }

    public function test_invalid_signature_value_returns_403(): void
    {
        $this->postJson(self::ENDPOINT, $this->groupPayload(), [
            'X-ZEvent-Signature' => 'mac='.str_repeat('0', 64),
        ])->assertStatus(403)->assertJson(['ok' => false]);
    }

    /**
     * Controller "fail-closed": chưa cấu hình ZALO_APP_ID / ZALO_OA_SECRET_KEY thì từ chối mọi
     * request, bất kể gửi kèm chữ ký gì.
     */
    public function test_unconfigured_app_id_and_secret_key_return_403(): void
    {
        config(['services.zalo.app_id' => null, 'services.zalo.secret_key' => null]);

        $this->postJson(self::ENDPOINT, $this->groupPayload(), [
            'X-ZEvent-Signature' => 'mac=anysignaturevalue',
        ])->assertStatus(403)->assertJson(['ok' => false]);
    }

    // ── Nhánh nhóm ────────────────────────────────────────────────────────────

    public function test_valid_group_message_with_shopee_url_queues_exactly_one_reply(): void
    {
        $this->postWithValidSignature($this->groupPayload())
            ->assertOk()
            ->assertJson(['ok' => true]);

        // Webhook phải trả lời NGAY: đúc mã tốn 10-60s, giữ webhook lâu như vậy là để Zalo
        // coi như timeout rồi gửi lại — mỗi lần gửi lại là thêm một comment lên Facebook.
        Queue::assertPushed(ReplyZaloGroupWithVouchers::class, 1);
    }

    /**
     * Zalo gửi lại cùng msg_id (retry). Cache::add() theo msg_id phải chặn lần thứ hai.
     */
    public function test_duplicate_msg_id_only_queues_one_reply_across_two_requests(): void
    {
        $payload = $this->groupPayload([
            'message' => [
                'text' => 'https://shopee.vn/product-i.111.222 mua đi',
                'msg_id' => 'dedup_msg_unique_999',
            ],
        ]);

        $this->postWithValidSignature($payload)->assertOk();
        $this->postWithValidSignature($payload)->assertOk();

        Queue::assertPushed(ReplyZaloGroupWithVouchers::class, 1);
    }

    /**
     * Regex cũ (`https?://\S*shopee\.vn\S*`) khớp cả link có "shopee.vn" nằm trong query của một
     * domain lạ. Lọt được cái này nghĩa là người lạ nhắn vào nhóm một link bất kỳ, và link đó
     * được comment lên Page Facebook rồi mở bằng Chromium đang đăng nhập — hai thứ không được
     * phép để người ngoài điều khiển.
     */
    public function test_a_foreign_host_disguised_with_shopee_in_the_query_is_rejected(): void
    {
        foreach ([
            'https://evil.com/?ref=shopee.vn',
            'https://shopee.vn.evil.com/product',
            'http://attacker.test/path#shp.ee',
        ] as $i => $url) {
            $this->postWithValidSignature($this->groupPayload([
                'message' => ['text' => "Sale to nha {$url}", 'msg_id' => "spoof_{$i}"],
            ]))->assertOk();
        }

        Queue::assertNothingPushed();
    }

    public function test_group_message_without_shopee_url_queues_nothing(): void
    {
        $payload = $this->groupPayload([
            'message' => [
                'text' => 'Xin chào nhóm! Không có link Shopee nào trong tin nhắn này.',
                'msg_id' => 'msg_no_url_001',
            ],
        ]);

        $this->postWithValidSignature($payload)->assertOk()->assertJson(['ok' => true]);

        Queue::assertNothingPushed();
    }

    /**
     * match() trong handle() có nhánh default trả ok:true cho mọi event_name lạ.
     */
    public function test_unknown_event_name_returns_ok_without_queueing(): void
    {
        $this->postWithValidSignature($this->groupPayload(['event_name' => 'oa_send_text']))
            ->assertOk()
            ->assertJson(['ok' => true]);

        Queue::assertNothingPushed();
    }
}
