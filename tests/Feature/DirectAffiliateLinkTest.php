<?php

namespace Tests\Feature;

use App\Models\FacebookGroup;
use App\Models\FacebookGroupDeal;
use App\Models\FacebookGroupPost;
use App\Models\Setting;
use App\Models\ShortLink;
use App\Services\DirectAffiliateLinkService;
use App\Services\FacebookGroupRunnerSettings;
use App\Services\ShopeeLinkResolverService;
use App\Services\UrlValidationService;
use App\Services\VoucherFetchService;
use App\Services\ZaloGroupLinkReplyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Công tắc "Nhóm FB & Zalo: chỉ đổi sang link affiliate" (DirectAffiliateLinkService): bật thì
 * không lấy mã, gửi thẳng link affiliate chính thức của Shopee thay cho link /go/ có mã.
 */
class DirectAffiliateLinkTest extends TestCase
{
    use RefreshDatabase;

    private const SHARE_URL = 'https://s.shopee.vn/abc';

    private const EXPECTED = 'https://s.shopee.vn/an_redir?origin_link=https%3A%2F%2Fshopee.vn%2Fproduct%2F11%2F22&affiliate_id=ours';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.shopee_affiliate.mmp_pid' => 'an_ours']);
        $this->travelTo(Carbon::parse('2026-10-03 09:00:00'));
    }

    private function switchOn(): void
    {
        Setting::set(DirectAffiliateLinkService::ENABLED_KEY, '1');
    }

    /** Link chia sẻ từ app bung ra trang sản phẩm 11/22; bật công tắc thì KHÔNG được hỏi nguồn mã. */
    private function fakeShopee(?array $product = ['product_name' => 'Tai nghe {Pro}', 'product_image' => 'https://cf.shopee.vn/file/x', 'discounted_price' => 199000]): void
    {
        $resolver = Mockery::mock(ShopeeLinkResolverService::class);
        $resolver->shouldReceive('resolveCanonicalUrl')->andReturnUsing(
            fn (string $url) => $url === self::SHARE_URL ? 'https://shopee.vn/product/11/22?d_id=x&utm_content=nguon' : $url
        );
        $resolver->shouldReceive('extractIds')->andReturnUsing(
            fn (string $url) => app(UrlValidationService::class)->extractShopeeIds($url) ?: null
        );
        $resolver->shouldReceive('fetchProductInfo')->andReturn($product);
        $this->app->instance(ShopeeLinkResolverService::class, $resolver);

        $fetcher = Mockery::mock(VoucherFetchService::class);
        $fetcher->shouldNotReceive('fetch');
        $this->app->instance(VoucherFetchService::class, $fetcher);
    }

    private function group(): FacebookGroup
    {
        return FacebookGroup::create([
            'fb_group_key' => 'g1',
            'name' => 'Nhóm 1',
            'url' => FacebookGroup::urlFor('g1'),
            'enabled' => true,
            'source' => 'sync',
        ]);
    }

    public function test_switch_is_off_by_default_and_saved_from_settings(): void
    {
        $this->assertFalse(DirectAffiliateLinkService::enabled());

        $admin = $this->createAdmin();
        $this->actingAs($admin)->post('/admin/settings', ['group_links_direct_affiliate' => true])->assertSessionHasNoErrors();
        $this->assertTrue(DirectAffiliateLinkService::enabled());

        $this->actingAs($admin)->post('/admin/settings', ['group_links_direct_affiliate' => false])->assertSessionHasNoErrors();
        $this->assertFalse(DirectAffiliateLinkService::enabled());
    }

    public function test_link_is_shopee_custom_link_with_our_affiliate_id(): void
    {
        $url = DirectAffiliateLinkService::url('11', '22', 'FBG');

        $this->assertSame(self::EXPECTED.'&sub_id=FBG', $url);
        $this->assertTrue(DirectAffiliateLinkService::isOwn($url));

        $this->assertFalse(DirectAffiliateLinkService::isOwn(str_replace('affiliate_id=ours', 'affiliate_id=other', $url)));
        $this->assertFalse(DirectAffiliateLinkService::isOwn(str_replace('s.shopee.vn', 'evil.example', $url)));
        $this->assertFalse(DirectAffiliateLinkService::isOwn(
            'https://s.shopee.vn/an_redir?origin_link='.urlencode('https://evil.example/product/11/22').'&affiliate_id=ours'
        ));
        $this->assertFalse(DirectAffiliateLinkService::isOwn(url('/go/abc')));
    }

    // ── Bài nhóm Facebook ────────────────────────────────────────────────────

    public function test_fb_compose_gives_direct_link_without_voucher_wording(): void
    {
        $this->switchOn();
        $this->fakeShopee();

        $data = $this->actingAs($this->createAdmin())
            ->postJson('/admin/fb-posts/compose', ['url' => self::SHARE_URL])->assertOk()->json();

        $this->assertSame('affiliate', $data['source']);
        $this->assertSame(self::EXPECTED.'&sub_id=FBG', $data['fallback_buy_url']);
        $this->assertNull($data['fallback_ytb_url']);
        $this->assertSame("👉 Link mua:\n".self::EXPECTED.'&sub_id=FBG', $data['link_block']);
        $this->assertStringContainsString('Tai nghe (Pro)', $data['caption']);
        $this->assertStringNotContainsString('mã', mb_strtolower($data['caption']));
        $this->assertSame(0, ShortLink::count());
    }

    public function test_store_accepts_our_direct_link_but_not_someone_elses(): void
    {
        $group = $this->group();
        $payload = [
            'shopee_url' => self::SHARE_URL,
            'caption' => "Deal\n\n{link}",
            'source' => 'affiliate',
            'group_ids' => [$group->id],
        ];
        $admin = $this->createAdmin();

        $this->actingAs($admin)->post('/admin/fb-posts', $payload + ['fallback_buy_url' => self::EXPECTED.'&sub_id=FBGx'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/admin/fb-posts', $payload + ['fallback_buy_url' => str_replace('ours', 'other', self::EXPECTED)])
            ->assertSessionHasErrors('fallback_buy_url');

        $this->assertSame(1, FacebookGroupDeal::count());
    }

    public function test_bot_posts_the_direct_link_when_switch_is_on(): void
    {
        $this->switchOn();
        $this->fakeShopee();
        $token = app(FacebookGroupRunnerSettings::class)->regenerateToken();
        $deal = FacebookGroupDeal::create([
            'shopee_url' => self::SHARE_URL,
            'caption' => "Deal hời\n\n{link}",
            'fallback_buy_url' => url('/go/cu123'),
        ]);
        $post = $deal->posts()->create(['facebook_group_id' => $this->group()->id, 'status' => 'pending', 'queued_at' => now()]);

        $caption = $this->postJson('/runner/fb/poll', ['claim_key' => 'claim-key-000000000001'], ['X-Runner-Token' => $token])
            ->assertOk()->assertJson(['type' => 'post', 'post_id' => $post->id])->json('caption');

        $this->assertSame("Deal hời\n\n👉 Link mua:\n".self::EXPECTED.'&sub_id=FBG', $caption);
        $post->refresh();
        $this->assertSame(FacebookGroupPost::CLAIMED, $post->status);
        $this->assertSame('fresh', $post->link_kind);
        $this->assertNull($post->short_link_id);
    }

    // ── Bot Zalo nhóm ────────────────────────────────────────────────────────

    public function test_zalo_replies_with_direct_link_when_switch_is_on(): void
    {
        $this->switchOn();
        $this->fakeShopee();

        $reply = app(ZaloGroupLinkReplyService::class)->replyFor(self::SHARE_URL);

        $this->assertSame("🛍️ Tai nghe {Pro}\n👉 Link mua:\n".self::EXPECTED.'&sub_id=ZL', $reply['text']);
        $this->assertSame(0, ShortLink::count());
    }

    public function test_zalo_explains_when_link_is_not_a_product(): void
    {
        $this->switchOn();
        $this->fakeShopee();

        $reply = app(ZaloGroupLinkReplyService::class)->replyFor('https://shopee.vn/shop/123');

        $this->assertStringContainsString('Chỉ đổi được link sản phẩm', $reply['text']);
        $this->assertStringNotContainsString('an_redir', $reply['text']);
    }

    public function test_zalo_still_answers_without_product_info(): void
    {
        $this->switchOn();
        $this->fakeShopee(product: null);

        $reply = app(ZaloGroupLinkReplyService::class)->replyFor(self::SHARE_URL);

        $this->assertStringStartsWith("🛍️ Link mua sản phẩm\n", $reply['text']);
        $this->assertStringContainsString('sub_id=ZL', $reply['text']);
    }
}
