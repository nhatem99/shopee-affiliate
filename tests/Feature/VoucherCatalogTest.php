<?php

namespace Tests\Feature;

use App\Models\VoucherOffer;
use App\Services\VoucherCatalogSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Trang /ma-giam-gia lấy mã từ API của một website KHÁC, nên hai thứ phải được khoá lại —
 * cả hai đều hỏng âm thầm, không có test thì chỉ phát hiện ra khi đối chiếu báo cáo Shopee
 * cuối tháng và thấy thiếu tiền:
 *
 *  1. Mọi link hiện cho khách phải mang affiliate ID CỦA MÌNH. Link nào không đổi được thì
 *     phải BỊ BỎ, không được lọt ra trang — lọt là mình chạy quảng cáo không công cho nguồn.
 *  2. Bộ tham số đã ký của Shopee (promotionId/signature/voucherCode) phải đi qua nguyên vẹn,
 *     sai một ký tự là khách bấm vào không được áp mã.
 */
class VoucherCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const MY_PID = 'an_17332410386';

    private const THEIR_PID = 'an_17305690359';

    private const SIGNATURE = 'e9403b4fb343b4e34d08759568a686ada146ce1d88905458cb276bbe1893a8da';

    /**
     * Câu trả lời hiện tại của nguồn. Đăng ký MỘT stub duy nhất ở setUp rồi đổi closure này,
     * thay vì gọi Http::fake() nhiều lần trong một test: lần gọi sau chỉ GỘP THÊM stub, và
     * stub đầu tiên khớp URL vẫn là stub thắng — nên "đổi câu trả lời giữa hai lượt đồng bộ"
     * kiểu đó im lặng không có tác dụng.
     */
    private \Closure $respond;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee_affiliate.mmp_pid' => self::MY_PID,
            'services.voucher_catalog.source_url' => 'https://nguon-ma.test/api/vouchers',
            'services.voucher_catalog.platforms' => ['shopee'],
            'services.voucher_catalog.utm_content' => 'MGG',
        ]);

        $this->respond = fn () => Http::response(['data' => ['items' => []]]);
        Http::fake(['nguon-ma.test/*' => fn () => ($this->respond)()]);
    }

    /** Một item đúng hình dạng nguồn trả về, mang affiliate ID của HỌ. */
    private function sourceItem(array $overrides = []): array
    {
        $claimLink = 'https://shopee.vn/universal-link/search?promotionId=1495551908941824'
            .'&signature='.self::SIGNATURE
            .'&voucherCode=SVIPSEM21'
            .'&mmp_pid='.self::THEIR_PID
            .'&utm_source='.self::THEIR_PID
            .'&utm_medium=affiliates&utm_campaign=id_mK6sD6fTEt&utm_content=MGG&utm_term=';

        return array_merge([
            'id' => '1495551908941824',
            'platform' => 'shopee',
            'code' => 'SVIPSEM21',
            'title' => 'Giảm 20%',
            'subtitle' => 'Tối đa 100.000d - Đơn tối thiểu 200.000d',
            'appliesText' => 'SP áp dụng',
            'shopName' => 'Shopee',
            'voucherImage' => 'https://down-vn.img.susercontent.com/file/abc.webp',
            'endTime' => 1790787540000,
            'category' => 'cashback',
            'usagePercent' => 0,
            'usageText' => 'Hết hạn sau: còn 24 ngày nữa',
            'isTest' => false,
            'claimLink' => $claimLink,
        ], $overrides);
    }

    private function fakeSource(array $items): void
    {
        $this->respond = fn () => Http::response([
            'success' => true,
            'data' => [
                'items' => $items,
                'pagination' => ['page' => 1, 'pageSize' => 50, 'total' => count($items), 'hasMore' => false],
            ],
        ]);
    }

    private function failSource(int $status): void
    {
        $this->respond = fn () => Http::response('', $status);
    }

    private function sync(): array
    {
        return app(VoucherCatalogSyncService::class)->sync();
    }

    public function test_sync_swaps_affiliate_id_to_our_own(): void
    {
        $this->fakeSource([$this->sourceItem()]);
        $this->sync();

        $offer = VoucherOffer::firstOrFail();
        parse_str(parse_url($offer->claim_url, PHP_URL_QUERY), $query);

        $this->assertSame(self::MY_PID, $query['mmp_pid']);
        $this->assertSame(self::MY_PID, $query['utm_source']);
        $this->assertStringNotContainsString(self::THEIR_PID, $offer->claim_url);
    }

    public function test_sync_keeps_signed_shopee_params_untouched(): void
    {
        $this->fakeSource([$this->sourceItem()]);
        $this->sync();

        parse_str(parse_url(VoucherOffer::firstOrFail()->claim_url, PHP_URL_QUERY), $query);

        $this->assertSame('1495551908941824', $query['promotionId']);
        $this->assertSame(self::SIGNATURE, $query['signature']);
        $this->assertSame('SVIPSEM21', $query['voucherCode']);
        // Cổng deep-link mở thẳng app Shopee trên điện thoại — đổi path là mất đường đó.
        $this->assertStringContainsString('/universal-link/search', VoucherOffer::firstOrFail()->claim_url);
    }

    public function test_sync_drops_source_campaign_id(): void
    {
        $this->fakeSource([$this->sourceItem()]);
        $this->sync();

        parse_str(parse_url(VoucherOffer::firstOrFail()->claim_url, PHP_URL_QUERY), $query);

        $this->assertArrayNotHasKey('utm_campaign', $query);
        $this->assertSame('MGG', $query['utm_content']);
    }

    public function test_sync_drops_links_it_cannot_rewrite(): void
    {
        $this->fakeSource([
            // Sàn khác: không trỏ shopee.vn nên không có mmp_pid của mình để thay.
            $this->sourceItem([
                'id' => '999',
                'code' => 'LAZADA9',
                'claimLink' => 'https://s.lazada.vn/s.ABC?sub_aff_id=nguon',
            ]),
            // Link shopee.vn nhưng không có mmp_pid: không tự thêm tham số mới vào URL.
            $this->sourceItem([
                'id' => '888',
                'code' => 'NOPID',
                'claimLink' => 'https://shopee.vn/universal-link/search?promotionId=1&voucherCode=NOPID',
            ]),
        ]);

        $report = $this->sync();

        $this->assertSame(0, VoucherOffer::count());
        $this->assertSame(2, $report['shopee']['dropped']);
        $this->assertSame(0, $report['shopee']['kept']);
    }

    /**
     * Nguồn trả cùng một mã ở nhiều trang (đo thật 23-09-2026: 104 item nhận về chỉ ra 102
     * mã). Phải đếm riêng, không được cộng vào `kept` — `kept` là số mã thật sự nằm trong
     * bảng, và đó là con số dùng để biết đồng bộ còn khoẻ hay không.
     */
    public function test_sync_counts_duplicate_ids_separately(): void
    {
        $this->fakeSource([
            $this->sourceItem(['id' => '111', 'code' => 'A']),
            $this->sourceItem(['id' => '111', 'code' => 'A']),
            $this->sourceItem(['id' => '222', 'code' => 'B']),
        ]);

        $report = $this->sync();

        $this->assertSame(2, VoucherOffer::count());
        $this->assertSame(3, $report['shopee']['fetched']);
        $this->assertSame(2, $report['shopee']['kept']);
        $this->assertSame(1, $report['shopee']['duplicated']);
        $this->assertSame(0, $report['shopee']['dropped']);
    }

    public function test_sync_skips_test_vouchers(): void
    {
        $this->fakeSource([$this->sourceItem(['isTest' => true])]);
        $this->sync();

        $this->assertSame(0, VoucherOffer::count());
    }

    public function test_sync_updates_existing_voucher_instead_of_duplicating(): void
    {
        $this->fakeSource([$this->sourceItem(['usagePercent' => 10])]);
        $this->sync();

        $this->fakeSource([$this->sourceItem(['usagePercent' => 85])]);
        $this->sync();

        $this->assertSame(1, VoucherOffer::count());
        $this->assertSame(85, VoucherOffer::firstOrFail()->usage_percent);
    }

    public function test_source_failure_leaves_previously_synced_vouchers_in_place(): void
    {
        $this->fakeSource([$this->sourceItem()]);
        $this->sync();

        $this->failSource(503);
        $report = $this->sync();

        // Trang vẫn còn mã cũ để hiện, nhưng lệnh phải báo hỏng chứ không im lặng.
        $this->assertSame(1, VoucherOffer::count());
        $this->assertNotNull($report['shopee']['error']);
    }

    public function test_page_shows_vouchers_to_guests(): void
    {
        $this->fakeSource([$this->sourceItem()]);
        $this->sync();

        $this->get('/ma-giam-gia')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Vouchers')
                ->has('initial.items', 1)
                ->where('initial.items.0.code', 'SVIPSEM21'));
    }

    public function test_logged_in_customer_link_carries_their_sub_id(): void
    {
        $this->fakeSource([$this->sourceItem()]);
        $this->sync();

        $user = $this->createUser();

        $response = $this->actingAs($user)->getJson('/api/vouchers?platform=shopee');
        $response->assertOk();

        parse_str(parse_url($response->json('items.0.claimUrl'), PHP_URL_QUERY), $query);

        // Quy ước 5 khe của ô Sub_id — xem AffiliateLinkRewriterService::buildSubId().
        $this->assertSame("MGG-{$user->sub_id}---", $query['utm_content']);
        $this->assertSame(self::MY_PID, $query['mmp_pid']);
    }

    public function test_guest_link_carries_channel_label_without_sub_id(): void
    {
        $this->fakeSource([$this->sourceItem()]);
        $this->sync();

        $response = $this->getJson('/api/vouchers');

        parse_str(parse_url($response->json('items.0.claimUrl'), PHP_URL_QUERY), $query);

        $this->assertSame('MGG', $query['utm_content']);
    }

    public function test_feed_paginates_and_keeps_order_stable(): void
    {
        $items = [];
        for ($i = 1; $i <= 5; $i++) {
            $items[] = $this->sourceItem(['id' => (string) $i, 'code' => "CODE{$i}", 'usagePercent' => $i]);
        }
        $this->fakeSource($items);
        $this->sync();

        $first = $this->getJson('/api/vouchers?page=1&page_size=2');
        $first->assertOk();
        $this->assertSame(5, $first->json('pagination.total'));
        $this->assertTrue($first->json('pagination.hasMore'));
        $this->assertSame(['CODE1', 'CODE2'], array_column($first->json('items'), 'code'));

        $second = $this->getJson('/api/vouchers?page=2&page_size=2');
        $this->assertSame(['CODE3', 'CODE4'], array_column($second->json('items'), 'code'));

        $last = $this->getJson('/api/vouchers?page=3&page_size=2');
        $this->assertFalse($last->json('pagination.hasMore'));
    }

    public function test_feed_filters_by_keyword(): void
    {
        $this->fakeSource([
            $this->sourceItem(['id' => '1', 'code' => 'FREESHIP50']),
            $this->sourceItem(['id' => '2', 'code' => 'GIAM20K']),
        ]);
        $this->sync();

        $response = $this->getJson('/api/vouchers?keyword=FREESHIP');

        $this->assertSame(1, $response->json('pagination.total'));
        $this->assertSame('FREESHIP50', $response->json('items.0.code'));
    }

    public function test_expired_vouchers_are_hidden(): void
    {
        $this->fakeSource([$this->sourceItem(['endTime' => now()->subHour()->getTimestampMs()])]);
        $this->sync();

        // Còn trong bảng (chưa quá 2 ngày nên chưa bị dọn) nhưng không được hiện ra trang.
        $this->assertSame(1, VoucherOffer::count());
        $this->getJson('/api/vouchers')->assertOk()->assertJsonPath('pagination.total', 0);
    }

    public function test_unknown_platform_is_rejected(): void
    {
        $this->getJson('/api/vouchers?platform=khong-ton-tai')->assertStatus(422);
    }
}
