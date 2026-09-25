<?php

namespace Tests\Feature;

use App\Exceptions\AffiliateScanException;
use App\Models\ApiConfig;
use App\Models\ShopeeOrder;
use App\Services\AccessTradeService;
use App\Services\UrlValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bốn cái chốt khiến đường TikTok không chạy trên hư không. Tất cả đều là loại hỏng IM LẶNG —
 * không có cái nào tự báo lỗi ra màn hình, nên nếu không khoá bằng test thì lần sau ai đó sửa
 * qua là mất luôn mà CI vẫn xanh:
 *
 *  1. Giá trị mẫu 'YOUR_*' của seeder phải bị coi như chưa cấu hình (nó đang nằm trên prod).
 *  2. Domain của ACCESSTRADE phải được phép làm đích short-link, không thì khách bấm mua ăn 422.
 *  3. Thẻ AccessTrade tắt thì lệnh đồng bộ không được gọi đi đâu cả.
 *  4. Đơn treo ngoài cửa sổ quét phải được đếm và kêu lên.
 */
class AccessTradeGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function seederRow(): void
    {
        // Y hệt ApiConfigSeeder — và y hệt dòng đang nằm trên production.
        ApiConfig::updateOrCreate(['platform' => AccessTradeService::PLATFORM], [
            'name' => 'AccessTrade',
            'endpoint' => 'https://api.accesstrade.vn/v1',
            'app_id' => 'YOUR_ACCESSTRADE_PUBLISHER_ID',
            'app_secret' => 'YOUR_ACCESSTRADE_API_KEY',
            'is_active' => false,
        ]);
    }

    // --- 1. Giá trị mẫu của seeder ---

    /**
     * Chuỗi mẫu KHÔNG rỗng nên cửa "chưa cấu hình" từng không chặn được: server vẫn bắn request
     * thật bằng token rác mỗi 2 giờ, nhận 401, và cảnh báo bị LOG_LEVEL=error trên prod nuốt.
     */
    public function test_gia_tri_mau_cua_seeder_bi_coi_nhu_chua_cau_hinh(): void
    {
        $this->seederRow();
        config(['services.accesstrade.api_key' => '']);
        Http::fake();

        $this->assertNull(app(AccessTradeService::class)->createProductLink('https://shop.tiktok.com/vn/pdp/123456789'));

        Http::assertNothingSent();
    }

    /** Và nó không được che mất campaign_id thật nằm trong config. */
    public function test_gia_tri_mau_khong_che_mat_campaign_id_that(): void
    {
        $this->seederRow();
        config([
            'services.accesstrade.api_key' => 'khoa-that',
            'services.accesstrade.campaign_id' => '6648523843406889655',
        ]);
        Http::fake(['*' => Http::response(['data' => ['success_link' => [['aff_link' => 'https://go.isclix.com/x']]]])]);

        app(AccessTradeService::class)->createProductLink('https://shop.tiktok.com/vn/pdp/123456789');

        Http::assertSent(fn ($request) => $request->data()['campaign_id'] === '6648523843406889655');
    }

    // --- 2. Domain đích ---

    /**
     * Link ACCESSTRADE cấp luôn nằm trên hai domain này (đo thật 25-09-2026). Thiếu chúng trong
     * danh sách cho phép thì ShortLinkController::store() trả 422 đúng ở cú bấm cuối cùng của
     * khách — tức cả đường TikTok chết mà không ai thấy gì ngoài một thông báo lỗi chung chung.
     */
    public function test_domain_cua_accesstrade_duoc_phep_lam_dich_short_link(): void
    {
        $validator = app(UrlValidationService::class);

        $validator->validateAffiliateRedirectUrl('https://go.isclix.com/deep_link/707627/6648523843406889655?url=abc');
        $validator->validateAffiliateRedirectUrl('https://shorten.asia/ex5tqGXY');

        $this->assertTrue(true); // không ném exception là đạt
    }

    /** Nới danh sách không được nới thành "cái gì cũng đi được". */
    public function test_domain_la_van_bi_chan(): void
    {
        $this->expectException(AffiliateScanException::class);

        app(UrlValidationService::class)->validateAffiliateRedirectUrl('https://dau-do-la.example.com/deep_link/1');
    }

    // --- 3. Công tắc bật/tắt ---

    public function test_the_accesstrade_tat_thi_lenh_dong_bo_khong_goi_gi(): void
    {
        $this->seederRow(); // is_active = false
        Http::fake();

        $this->artisan('accesstrade:sync-orders')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_bat_the_len_thi_lenh_chay(): void
    {
        $this->seederRow();
        ApiConfig::where('platform', AccessTradeService::PLATFORM)->update(['is_active' => true]);
        config(['services.accesstrade.api_key' => 'khoa-that']);
        Http::fake(['*' => Http::response(['data' => [], 'total' => 0])]);

        $this->artisan('accesstrade:sync-orders', ['--days' => 30])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'order-list'));
    }

    /** Chưa có bản ghi nào = chưa cài đặt = tắt, không phải "cứ chạy đi". */
    public function test_chua_co_ban_ghi_thi_coi_nhu_tat(): void
    {
        $this->assertFalse(app(AccessTradeService::class)->enabled());
    }

    // --- 4. Đơn treo ngoài cửa sổ quét ---

    /**
     * Cửa sổ quét lọc theo ngày PHÁT SINH đơn, mà cờ đối soát về theo kỳ (có thể sau cả tháng).
     * Đơn rơi ra khỏi cửa sổ trước khi được xác nhận sẽ treo 'pending' vĩnh viễn — khách không
     * bao giờ nhận tiền, và không có gì báo. Lệnh phải tự đếm và kêu lên.
     */
    public function test_don_treo_ngoai_cua_so_quet_thi_lenh_keu_len(): void
    {
        $this->seederRow();
        ApiConfig::where('platform', AccessTradeService::PLATFORM)->update(['is_active' => true]);
        config(['services.accesstrade.api_key' => 'khoa-that']);
        Http::fake(['*' => Http::response(['data' => [], 'total' => 0])]);

        ShopeeOrder::create([
            'platform' => ShopeeOrder::PLATFORM_TIKTOK,
            'order_id' => 'DON-CU',
            'item_id' => '',
            'model_id' => '',
            'status' => 'pending',
            'net_commission' => 10000,
            'ordered_at' => now()->subDays(75),
        ]);

        $this->artisan('accesstrade:sync-orders', ['--days' => 30])
            ->expectsOutputToContain('1 đơn TikTok quá 30 ngày')
            ->assertSuccessful();
    }

    public function test_don_con_trong_cua_so_thi_khong_keu(): void
    {
        $this->seederRow();
        ApiConfig::where('platform', AccessTradeService::PLATFORM)->update(['is_active' => true]);
        config(['services.accesstrade.api_key' => 'khoa-that']);
        Http::fake(['*' => Http::response(['data' => [], 'total' => 0])]);

        ShopeeOrder::create([
            'platform' => ShopeeOrder::PLATFORM_TIKTOK,
            'order_id' => 'DON-MOI',
            'item_id' => '',
            'model_id' => '',
            'status' => 'pending',
            'net_commission' => 10000,
            'ordered_at' => now()->subDays(10),
        ]);

        $this->artisan('accesstrade:sync-orders', ['--days' => 90])
            ->doesntExpectOutputToContain('vẫn \'đang chờ\'')
            ->assertSuccessful();
    }
}
