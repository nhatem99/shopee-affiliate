<?php

namespace Tests\Feature;

use App\Jobs\ResolveVoucherLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Luồng bất đồng bộ của công cụ lấy mã: POST /voucher/resolve trả token ngay, việc nặng chạy
 * trong ResolveVoucherLinks, frontend hỏi lại qua GET /voucher/status/{token}.
 */
class VoucherResolveFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SHOPEE_URL = 'https://shopee.vn/Ao-Hoodie-i.973033125.26480867602';

    private const MINTED_URL = 'https://shopee.vn/product/973033125/26480867602?credential_token=abc&encrypted_payload=def&mmp_pid=an_KOL';

    // Công cụ chỉ mở cho khách dùng điện thoại — mọi request trong test phải giả lập UA di động.
    private const MOBILE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    private function scan(string $url = self::SHOPEE_URL)
    {
        return $this->withHeaders(['User-Agent' => self::MOBILE_UA])->post('/voucher/resolve', ['url' => $url]);
    }

    private function enableMinting(): void
    {
        config([
            'services.channel_voucher.enabled' => true,
            'services.channel_voucher.channels' => ['fb'],
            'services.facebook.post_id' => '111_222',
            'services.facebook.page_access_token' => 'test-page-token',
            'services.browser_resolver.url' => 'http://resolver.test',
            'services.shopee_affiliate.kol_pid' => 'an_KOL',
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/resolve')) {
                return Http::response(['final_url' => self::MINTED_URL, 'duration_ms' => 3000]);
            }
            if (str_contains($url, '/comments')) {
                return Http::response(['id' => '111_333']);
            }
            if (str_contains($url, '111_333')) {
                return Http::response(['id' => '111_333', 'permalink_url' => 'https://www.facebook.com/p?comment_id=333']);
            }

            // Tra cứu sản phẩm trên Shopee — không phải trọng tâm test này.
            return Http::response([], 500);
        });
    }

    public function test_resolve_returns_a_token_instead_of_waiting_for_the_result(): void
    {
        Queue::fake();

        $this->scan()
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Home')->has('voucherJob.token'));

        Queue::assertPushed(ResolveVoucherLinks::class);
    }

    public function test_desktop_visitors_are_still_turned_away(): void
    {
        Queue::fake();

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)'])
            ->post('/voucher/resolve', ['url' => self::SHOPEE_URL])
            ->assertSessionHasErrors('voucher_url');

        Queue::assertNothingPushed();
    }

    public function test_status_serves_the_minted_links_once_the_job_has_run(): void
    {
        $this->enableMinting();

        // QUEUE_CONNECTION=sync trong test: job chạy ngay trong request, nên kết quả đã sẵn sàng.
        $token = $this->scan()->viewData('page')['props']['voucherJob']['token'];

        $body = $this->getJson("/voucher/status/{$token}")->assertOk()->json();

        $this->assertSame('done', $body['status']);
        $this->assertSame('Mã FB', $body['result']['voucher_links']['facebook'][0]['label']);
        $this->assertSame(['Mã FB'], $body['result']['voucher_labels']);

        // URL thật KHÔNG được lộ trong response — frontend chỉ nhận token mờ 'ref'.
        $this->assertStringNotContainsString('credential_token', json_encode($body));
        $this->assertSame(
            self::MINTED_URL,
            Cache::get('voucher_ref:'.$body['result']['voucher_links']['facebook'][0]['ref']),
        );
    }

    public function test_no_codes_are_served_when_minting_is_off(): void
    {
        // Không còn nguồn dự phòng nào sau khi gỡ salesoc.vn: tắt đường tự đúc là hết mã thật.
        // Vẫn phải trả 'done' (không phải 'failed') để Home.vue hiện được khung sản phẩm kèm
        // "Chưa lấy được link voucher", thay vì báo lỗi chung chung.
        config(['services.channel_voucher.enabled' => false]);
        Http::fake();

        $token = $this->scan()->viewData('page')['props']['voucherJob']['token'];

        $body = $this->getJson("/voucher/status/{$token}")->assertOk()->json();

        $this->assertSame('done', $body['status']);
        $this->assertSame([], $body['result']['voucher_links']);
        $this->assertSame([], $body['result']['voucher_labels']);
    }

    public function test_the_same_product_is_only_minted_once_within_the_cache_window(): void
    {
        $this->enableMinting();

        $this->scan();
        $this->scan();

        // Mỗi lần đúc là một comment THẬT lên Facebook. Hai khách cùng xem một sản phẩm phải
        // dùng chung một lần đúc, nếu không bài đăng ngập comment và Page bị đánh dấu spam.
        $commentsPosted = Http::recorded(
            fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/comments')
        )->count();

        $this->assertSame(1, $commentsPosted);
    }

    public function test_an_unknown_token_reports_an_expired_session(): void
    {
        $this->getJson('/voucher/status/'.str_repeat('a', 32))
            ->assertOk()
            ->assertJson(['status' => 'expired']);
    }

    public function test_a_malformed_token_does_not_reach_the_controller(): void
    {
        // Ràng buộc [A-Za-z0-9]{32} ở route — chặn mọi thứ trước khi nó thành key cache.
        $this->getJson('/voucher/status/../../etc/passwd')->assertNotFound();
    }
}
