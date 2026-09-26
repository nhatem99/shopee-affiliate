<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\CashbackService;
use App\Services\KieuShopeeService;
use App\Services\ShopeeProductLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * "Hoàn tiền dự kiến" trên thẻ kết quả: hoa hồng thật của sản phẩm × tỉ lệ hoàn của mình.
 *
 * Hai thứ dễ sai và cả hai đều sai theo kiểu KHÔNG AI THẤY:
 *
 *  1. `sellerRate`/`shopeeRate` của nguồn là PHÂN SỐ (0.1 + 0.04 = 0.14), không phải phần trăm.
 *     Nhầm một lần là con số hứa với khách lệch 100 lần — và nó vẫn hiện ra rất tự tin.
 *  2. Lượt hỏi hoa hồng phải nằm NGOÀI đường quét. Nhét vào trong là mọi khách dán link đều
 *     chịu thêm timeout 10 giây của một proxy bên thứ ba không có SLA, chỉ để đổi lấy một con
 *     số ước tính.
 */
class CashbackEstimateTest extends TestCase
{
    use RefreshDatabase;

    private const SHOPEE_URL = 'https://shopee.vn/Ao-Hoodie-i.564687320.29261186260';

    private const ITEM_ID = '29816681536';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // KHÔNG gọi Http::fake() ở đây: stub đăng ký TRƯỚC sẽ khớp trước, nên một catch-all ở
        // setUp nuốt luôn mọi stub cụ thể của từng test và chúng đều nhận về response rỗng.
        // Mỗi test tự dựng đúng thứ nó cần.
    }

    /** Response thật của nguồn (đo 26-09-2026 trên chính item này). */
    private function fakeLookup(float $commission = 37660, float $sellerRate = 0.1, float $shopeeRate = 0.04): void
    {
        Http::fake([
            ShopeeProductLookupService::BASE_URL.'*' => Http::response([
                'productInfo' => [
                    'productName' => 'Quạt gấp 180° GOOJODOQ',
                    'price' => 269000,
                    'commission' => $commission,
                    'sellerRate' => $sellerRate,
                    'shopeeRate' => $shopeeRate,
                    'imageUrl' => 'https://cf.shopee.vn/file/abc',
                ],
            ]),
        ]);
    }

    private function enableCashback(string $rate = '50'): void
    {
        Setting::set(CashbackService::RATE_KEY, $rate);
    }

    public function test_tra_ve_hoa_hong_bang_tien_cua_dung_san_pham(): void
    {
        $this->enableCashback();
        $this->fakeLookup();

        $this->getJson('/voucher/hoa-hong/'.self::ITEM_ID)
            ->assertOk()
            ->assertJson(['commission' => 37660]);
    }

    /**
     * Giao diện nhân số này với tỉ lệ hoàn (50%) để ra "dự kiến ~18.830đ". Khoá lại phép nhân đó
     * ở đây bằng chính công thức CashbackService dùng khi ghi tiền thật — hai bên lệch nhau là
     * trang hứa một đằng, ví cộng một nẻo.
     */
    public function test_so_tien_du_kien_khop_voi_cong_thuc_ghi_tien_that(): void
    {
        $this->enableCashback('50');
        $this->fakeLookup();

        $commission = (float) $this->getJson('/voucher/hoa-hong/'.self::ITEM_ID)->json('commission');

        $duKien = round($commission * 50 / 100);
        $this->assertSame(18830.0, $duKien);
    }

    /** Chương trình hoàn tiền tắt: không ước tính, và cũng không gọi sang nguồn ngoài làm gì. */
    public function test_hoan_tien_tat_thi_khong_goi_nguon_ngoai(): void
    {
        Setting::set(CashbackService::RATE_KEY, '0');
        $this->fakeLookup();

        $this->getJson('/voucher/hoa-hong/'.self::ITEM_ID)
            ->assertOk()
            ->assertJson(['commission' => null]);

        Http::assertNothingSent();
    }

    /** Nguồn hỏng/không có hoa hồng: trả null để giao diện im lặng bỏ qua, không đoán bừa. */
    public function test_nguon_hong_thi_tra_null_chu_khong_doan(): void
    {
        $this->enableCashback();
        Http::fake([ShopeeProductLookupService::BASE_URL.'*' => Http::response('', 500)]);

        $this->getJson('/voucher/hoa-hong/'.self::ITEM_ID)
            ->assertOk()
            ->assertJson(['commission' => null]);
    }

    public function test_item_id_rac_bi_tu_choi(): void
    {
        $this->enableCashback();
        Http::fake();

        $this->getJson('/voucher/hoa-hong/abc')->assertStatus(422);
        Http::assertNothingSent();
    }

    /** Sản phẩm hot: nhiều khách cùng dán một link không được thành nhiều lượt gọi nguồn ngoài. */
    public function test_ket_qua_duoc_cache_khong_goi_lai_nguon(): void
    {
        $this->enableCashback();
        $this->fakeLookup();

        $this->getJson('/voucher/hoa-hong/'.self::ITEM_ID)->assertOk();
        $this->getJson('/voucher/hoa-hong/'.self::ITEM_ID)->assertOk();

        Http::assertSentCount(1);
    }

    /**
     * Ranh giới quan trọng nhất về tốc độ: lượt quét KHÔNG được gọi sang nguồn hoa hồng. Nó chỉ
     * trả item_id để giao diện tự hỏi sau.
     */
    public function test_luot_quet_khong_goi_nguon_hoa_hong_nhung_co_tra_item_id(): void
    {
        $this->enableCashback();
        Http::fake();

        $mock = Mockery::mock(KieuShopeeService::class);
        $mock->shouldReceive('fetchProductAndVoucherLink')->andReturn([
            'voucher_link' => 'https://shopee.vn/product-i.1.2?mmp_pid=x',
            'shop_id' => '564687320',
            'item_id' => '29261186260',
            'product' => ['product_name' => 'Áo Hoodie', 'discounted_price' => 100000],
        ]);
        $this->app->instance(KieuShopeeService::class, $mock);

        $this->actingAs($this->createAdmin())
            ->post('/voucher/resolve', ['url' => self::SHOPEE_URL])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('voucherResult.item_id', '29261186260'));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'data.addlivetag.com'));
    }
}
