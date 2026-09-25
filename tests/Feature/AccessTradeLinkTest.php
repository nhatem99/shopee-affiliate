<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Services\AccessTradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sinh link affiliate TikTok Shop qua ACCESSTRADE (AccessTradeService).
 *
 * Mọi mẫu response dưới đây chép từ lần gọi THẬT trên production ngày 25-09-2026 bằng API key
 * của tài khoản — không phải đoán theo tài liệu. Chỗ dễ sai nhất và cũng đắt nhất nếu sai là
 * `sub1`: mất nó thì link vẫn chạy, khách vẫn mua được, chỉ có điều đơn không quy về ai để hoàn
 * tiền — hỏng âm thầm, tới kỳ đối soát mới lộ.
 */
class AccessTradeLinkTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_URL = 'https://shop.tiktok.com/view/product/1733724538346374522?region=VN&local=en';

    private const ENDPOINT = 'https://api.accesstrade.vn/v1/product_link/create';

    protected function setUp(): void
    {
        parent::setUp();

        ApiConfig::updateOrCreate(['platform' => AccessTradeService::PLATFORM], [
            'name' => 'AccessTrade',
            'endpoint' => 'https://api.accesstrade.vn/v1',
            'app_id' => '6648523843406889655',
            'app_secret' => 'khoa-test',
            'is_active' => true,
        ]);
    }

    private function service(): AccessTradeService
    {
        return app(AccessTradeService::class);
    }

    /** Response thật của ACCESSTRADE khi tạo link thành công. */
    private function fakeSuccess(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'data' => [
                'error_link' => [],
                'success_link' => [[
                    'aff_link' => 'https://go.isclix.com/deep_link/7076279747515259482/6648523843406889655?url=abc&sub1=u7k2m9',
                    'first_link' => null,
                    'short_link' => 'https://shorten.asia/hY56S26r',
                    'url_origin' => self::PRODUCT_URL,
                ]],
                'suspend_url' => [],
            ],
            'success' => true,
        ])]);
    }

    public function test_tra_ve_ca_link_day_du_lan_link_rut_gon(): void
    {
        $this->fakeSuccess();

        $result = $this->service()->createProductLink(self::PRODUCT_URL, 'u7k2m9');

        $this->assertSame('https://shorten.asia/hY56S26r', $result['short_link']);
        $this->assertStringStartsWith('https://go.isclix.com/deep_link/', $result['aff_link']);
        $this->assertSame(self::PRODUCT_URL, $result['url_origin']);
    }

    /**
     * Mã khách phải nằm ở CẢ utm_content LẪN sub1 — đây là đường duy nhất để hoàn tiền cho đúng
     * người. utm_content là ô có tên trong danh sách trường của báo cáo đơn hàng v2; sub1 thì
     * tài liệu báo cáo không nhắc tới, nhưng gửi thừa không tốn gì còn gửi thiếu thì mất đơn.
     */
    public function test_ma_khach_di_vao_ca_utm_content_lan_sub1(): void
    {
        $this->fakeSuccess();

        $this->service()->createProductLink(self::PRODUCT_URL, 'u7k2m9');

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $body['utm_content'] === 'u7k2m9'
                && $body['sub1'] === 'u7k2m9'
                && $body['campaign_id'] === '6648523843406889655'
                && $body['urls'] === [self::PRODUCT_URL]
                && $request->hasHeader('Authorization', 'Token khoa-test');
        });
    }

    /**
     * Khách vãng lai: KHÔNG được gửi sub1 rỗng. ACCESSTRADE ghi nguyên văn giá trị nhận được vào
     * link, nên chuỗi rỗng là một khe rác trong báo cáo, khác hẳn với việc không có khe nào.
     */
    public function test_khach_vang_lai_thi_khong_gui_o_ma_khach_nao(): void
    {
        $this->fakeSuccess();

        $this->service()->createProductLink(self::PRODUCT_URL);

        Http::assertSent(fn (Request $request) => ! array_key_exists('sub1', $request->data())
            && ! array_key_exists('utm_content', $request->data()));
    }

    /**
     * Họ trả HTTP 200 kèm giỏ error_link/suspend_url khi từ chối url (sai chiến dịch, sản phẩm
     * bị treo). Tin vào mã 200 là trả cho khách một link rỗng.
     */
    public function test_http_200_nhung_khong_co_link_thi_coi_nhu_that_bai(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'data' => [
                'error_link' => [['url' => self::PRODUCT_URL, 'message' => 'Campaign not approved']],
                'success_link' => [],
                'suspend_url' => [],
            ],
            'success' => true,
        ])]);

        $this->assertNull($this->service()->createProductLink(self::PRODUCT_URL, 'u7k2m9'));
    }

    public function test_api_loi_thi_tra_null_chu_khong_no(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['message' => 'Unauthorized'], 401)]);

        $this->assertNull($this->service()->createProductLink(self::PRODUCT_URL, 'u7k2m9'));
    }

    /** Chưa cấu hình thì không được gọi đi đâu cả — gửi request rỗng chỉ tốn quota 30 req/phút. */
    public function test_thieu_key_thi_khong_goi_api(): void
    {
        // Qua model chứ không qua query builder: app_secret có cast 'encrypted', update thẳng
        // bằng query sẽ ghi chuỗi thô rồi lúc đọc lại nổ "The payload is invalid".
        $config = ApiConfig::where('platform', AccessTradeService::PLATFORM)->first();
        $config->app_secret = '';
        $config->save();
        config(['services.accesstrade.api_key' => '']);
        Http::fake();

        $this->assertNull($this->service()->createProductLink(self::PRODUCT_URL));

        Http::assertNothingSent();
    }

    /** Tắt is_active (đang dùng nguồn khác) KHÔNG được làm mất tham số admin đã dán — cùng bài học với KieuShopeeService::params(). */
    public function test_tat_is_active_van_dung_tham_so_cua_admin(): void
    {
        ApiConfig::where('platform', AccessTradeService::PLATFORM)->update(['is_active' => false]);
        $this->fakeSuccess();

        $this->assertNotNull($this->service()->createProductLink(self::PRODUCT_URL));

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Token khoa-test'));
    }

    public function test_nut_kiem_tra_ket_noi_noi_ro_hong_cho_nao(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['message' => 'Unauthorized'], 401)]);

        $result = $this->service()->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('API key', $result['message']);
    }

    public function test_nut_kiem_tra_ket_noi_bao_thanh_cong_kem_link(): void
    {
        $this->fakeSuccess();

        $result = $this->service()->testConnection();

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('shorten.asia', $result['message']);
    }
}
