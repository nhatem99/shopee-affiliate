<?php

namespace Tests\Feature;

use App\Models\FlashSaleItem;
use App\Services\FlashSaleSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Trang /flashsale KHÔNG dùng affiliate_link của nguồn — tự dựng link sản phẩm từ
 * shopid+itemid (xem docblock FlashSaleSyncService). Hai thứ phải khoá lại:
 *
 *  1. Mọi link hiện cho khách phải mang mmp_pid CỦA MÌNH, dù nguồn có cấp
 *     affiliate_link hay không, và dù nguồn KHÔNG cấp affiliate_link cho item đó.
 *  2. Nguồn trả TOÀN BỘ danh sách mỗi lần gọi — sản phẩm vắng mặt ở lượt mới phải bị
 *     dọn, NHƯNG một lượt đồng bộ hỏng (trả rỗng/lỗi) không được xoá sạch dữ liệu tốt
 *     của lượt trước.
 */
class FlashSaleTest extends TestCase
{
    use RefreshDatabase;

    private const MY_PID = 'an_17332410386';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee_affiliate.mmp_pid' => self::MY_PID,
            'services.flash_sale.source_url' => 'https://nguon-fs.test/apidata.php',
            'services.flash_sale.utm_content' => 'FS',
            'services.flash_sale.min_amount' => 1,
        ]);

        $this->respond = fn () => Http::response([]);
        Http::fake(['nguon-fs.test/*' => fn () => ($this->respond)()]);
    }

    private \Closure $respond;

    /** Một item đúng hình dạng nguồn trả về. affiliate_link CỐ Ý để trống mặc định. */
    private function sourceItem(array $overrides = []): array
    {
        return array_merge([
            'shopid' => 408960220,
            'itemid' => 23279656945,
            'img' => 'https://down-vn.img.susercontent.com/file/abc.webp',
            'title' => 'Mặt nạ FOODAHOLIC Derma Mask',
            'price' => 1000,
            'original_price' => '15000',
            'percent' => 93,
            'raw_percent' => 93,
            'amount' => 25,
            'rating_count' => 0,
            'rating_star' => 4.93,
            'sold' => 0,
            'time' => '09:00',
            'promotionid' => 502239806959811,
            'affiliate_link' => '',
        ], $overrides);
    }

    private function fakeSource(array $items): void
    {
        $this->respond = fn () => Http::response($items);
    }

    private function failSource(int $status): void
    {
        $this->respond = fn () => Http::response('', $status);
    }

    private function sync(): array
    {
        return app(FlashSaleSyncService::class)->sync();
    }

    public function test_sync_builds_own_link_even_when_source_gives_none(): void
    {
        $this->fakeSource([$this->sourceItem(['affiliate_link' => ''])]);
        $this->sync();

        $item = FlashSaleItem::firstOrFail();
        parse_str(parse_url($item->claim_url, PHP_URL_QUERY), $query);

        $this->assertSame(self::MY_PID, $query['mmp_pid']);
        $this->assertSame(self::MY_PID, $query['utm_source']);
        $this->assertStringContainsString('shopee.vn/product/408960220/23279656945', $item->claim_url);
    }

    public function test_sync_ignores_source_affiliate_link_even_when_present(): void
    {
        // Nguồn cấp affiliate_link thuộc affiliate của HỌ — phải hoàn toàn không dùng tới.
        $this->fakeSource([$this->sourceItem(['affiliate_link' => 'https://s.shopee.vn/W6WG7WVHk'])]);
        $this->sync();

        $item = FlashSaleItem::firstOrFail();
        $this->assertStringNotContainsString('s.shopee.vn', $item->claim_url);
        $this->assertStringContainsString('mmp_pid='.self::MY_PID, $item->claim_url);
    }

    public function test_sync_drops_items_with_zero_amount(): void
    {
        $this->fakeSource([
            $this->sourceItem(['itemid' => 1, 'amount' => 0]),
            $this->sourceItem(['itemid' => 2, 'amount' => 25]),
        ]);
        $report = $this->sync();

        $this->assertSame(1, FlashSaleItem::count());
        $this->assertSame(1, $report['dropped']);
        $this->assertSame(1, $report['kept']);
    }

    public function test_sync_drops_items_missing_required_fields(): void
    {
        $this->fakeSource([
            $this->sourceItem(['itemid' => 1, 'title' => '']),
            $this->sourceItem(['itemid' => 2, 'price' => 0]),
        ]);
        $report = $this->sync();

        $this->assertSame(0, FlashSaleItem::count());
        $this->assertSame(2, $report['dropped']);
    }

    public function test_same_item_can_appear_in_multiple_time_slots(): void
    {
        $this->fakeSource([
            $this->sourceItem(['time' => '09:00']),
            $this->sourceItem(['time' => '12:00']),
        ]);
        $this->sync();

        $this->assertSame(2, FlashSaleItem::count());
    }

    public function test_sync_updates_existing_item_instead_of_duplicating(): void
    {
        $this->fakeSource([$this->sourceItem(['amount' => 25])]);
        $this->sync();

        $this->fakeSource([$this->sourceItem(['amount' => 3])]);
        $this->sync();

        $this->assertSame(1, FlashSaleItem::count());
        $this->assertSame(3, FlashSaleItem::firstOrFail()->amount);
    }

    public function test_items_absent_from_latest_full_snapshot_are_pruned(): void
    {
        $this->fakeSource([
            $this->sourceItem(['itemid' => 1]),
            $this->sourceItem(['itemid' => 2]),
        ]);
        $this->sync();
        $this->assertSame(2, FlashSaleItem::count());

        // Lượt sau nguồn chỉ còn item 1 — item 2 đã "đóng suất", phải bị dọn.
        $this->fakeSource([$this->sourceItem(['itemid' => 1])]);
        $this->sync();

        $this->assertSame(1, FlashSaleItem::count());
        $this->assertSame(1, FlashSaleItem::firstOrFail()->itemid);
    }

    public function test_empty_source_response_does_not_wipe_existing_data(): void
    {
        $this->fakeSource([$this->sourceItem(['itemid' => 1])]);
        $this->sync();
        $this->assertSame(1, FlashSaleItem::count());

        // Nguồn trả mảng rỗng hợp lệ (không phải lỗi HTTP) — có thể là sự cố tạm thời bên
        // họ, không phải "cả ngày hết flash sale". Không được xoá dữ liệu tốt đang có.
        $this->fakeSource([]);
        $report = $this->sync();

        $this->assertSame(1, FlashSaleItem::count());
        $this->assertSame(0, $report['pruned']);
    }

    public function test_source_http_failure_leaves_previous_data_in_place(): void
    {
        $this->fakeSource([$this->sourceItem(['itemid' => 1])]);
        $this->sync();

        $this->failSource(503);
        $report = $this->sync();

        $this->assertSame(1, FlashSaleItem::count());
        $this->assertNotNull($report['error']);
    }

    public function test_page_shows_items_to_guests(): void
    {
        $this->fakeSource([$this->sourceItem()]);
        $this->sync();

        $this->get('/flashsale')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('FlashSale')
                ->has('initial.items', 1)
                ->where('initial.items.0.timeSlot', '09:00'));
    }

    public function test_logged_in_customer_link_carries_their_sub_id(): void
    {
        $this->fakeSource([$this->sourceItem()]);
        $this->sync();

        $user = $this->createUser();
        $response = $this->actingAs($user)->getJson('/api/flashsale');

        parse_str(parse_url($response->json('items.0.claimUrl'), PHP_URL_QUERY), $query);

        $this->assertSame("FS-{$user->sub_id}---", $query['utm_content']);
    }

    public function test_guest_link_carries_channel_label_without_sub_id(): void
    {
        $this->fakeSource([$this->sourceItem()]);
        $this->sync();

        $response = $this->getJson('/api/flashsale');
        parse_str(parse_url($response->json('items.0.claimUrl'), PHP_URL_QUERY), $query);

        $this->assertSame('FS', $query['utm_content']);
    }

    public function test_feed_filters_by_slot(): void
    {
        $this->fakeSource([
            $this->sourceItem(['itemid' => 1, 'time' => '09:00']),
            $this->sourceItem(['itemid' => 2, 'time' => '12:00']),
        ]);
        $this->sync();

        $response = $this->getJson('/api/flashsale?slot=12:00');

        $this->assertSame(1, $response->json('pagination.total'));
        $this->assertSame('12:00', $response->json('items.0.timeSlot'));
    }

    public function test_feed_filters_by_keyword(): void
    {
        $this->fakeSource([
            $this->sourceItem(['itemid' => 1, 'title' => 'Mặt nạ dưỡng ẩm']),
            $this->sourceItem(['itemid' => 2, 'title' => 'Bánh trung thu']),
        ]);
        $this->sync();

        $response = $this->getJson('/api/flashsale?keyword=trung thu');

        $this->assertSame(1, $response->json('pagination.total'));
        $this->assertSame('Bánh trung thu', $response->json('items.0.title'));
    }

    public function test_unknown_slot_is_rejected(): void
    {
        $this->fakeSource([$this->sourceItem()]);
        $this->sync();

        $this->getJson('/api/flashsale?slot=99:99')->assertStatus(422);
    }

    public function test_items_sorted_by_percent_descending(): void
    {
        $this->fakeSource([
            $this->sourceItem(['itemid' => 1, 'percent' => 20]),
            $this->sourceItem(['itemid' => 2, 'percent' => 90]),
            $this->sourceItem(['itemid' => 3, 'percent' => 50]),
        ]);
        $this->sync();

        $response = $this->getJson('/api/flashsale');

        $this->assertSame([90, 50, 20], array_column($response->json('items'), 'percent'));
    }
}
