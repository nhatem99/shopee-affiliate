<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Models\User;
use App\Services\AccessTradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Cửa vào /voucher/resolve giờ nhận CẢ link TikTok Shop, và nhánh đó đi một đường khác hẳn
 * nhánh Shopee.
 *
 * Ba lời hứa của trang KHÔNG áp dụng cho TikTok, và cả ba đều là loại sai mà khách chỉ phát
 * hiện sau khi đã mua:
 *  • Không có mã giảm giá — TikTok không có chuyện "link đã áp sẵn mã".
 *  • Không vòng qua Facebook — bước comment/reel tồn tại để mã Shopee có hiệu lực.
 *  • Mã khách phải gắn NGAY lúc tạo link, không đợi lúc bấm mua như bên Shopee.
 */
class TikTokScanTest extends TestCase
{
    use RefreshDatabase;

    private const TIKTOK_SHORT = 'https://vt.tiktok.com/ZS9A4R76bVmje-pJ8pO/';

    private const PRODUCT_ID = '1732117639001048907';

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

    /** Chuỗi thật: link rút gọn 301 sang /vn/pdp/{id}, rồi ACCESSTRADE trả link tracking. */
    private function fakeChuoiThat(): void
    {
        Http::fake([
            'vt.tiktok.com/*' => Http::response('', 301, [
                'Location' => 'https://shop.tiktok.com/vn/pdp/'.self::PRODUCT_ID.'?_d=abc&chain_key=xyz',
            ]),
            'api.accesstrade.vn/*' => Http::response([
                'data' => [
                    'error_link' => [],
                    'success_link' => [[
                        'aff_link' => 'https://go.isclix.com/deep_link/707/664?url=abc&sub1=u7k2m9',
                        'short_link' => 'https://shorten.asia/ex5tqGXY',
                        'url_origin' => 'https://shop.tiktok.com/vn/pdp/'.self::PRODUCT_ID,
                    ]],
                    'suspend_url' => [],
                ],
                'success' => true,
            ]),
        ]);
    }

    private function dan(string $url): TestResponse
    {
        // Công cụ chỉ mở cho mobile hoặc admin — dùng admin để khỏi phải giả UA điện thoại.
        return $this->actingAs($this->createAdmin())->post('/voucher/resolve', ['url' => $url]);
    }

    public function test_dan_link_tiktok_rut_gon_ra_duoc_link_mua(): void
    {
        $this->fakeChuoiThat();

        $this->dan(self::TIKTOK_SHORT)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('voucherResult.platform', 'tiktok')
                ->where('voucherResult.buy_url', 'https://shorten.asia/ex5tqGXY')
                ->where('voucherResult.canonical_url', 'https://shop.tiktok.com/vn/pdp/'.self::PRODUCT_ID)
            );
    }

    /**
     * Cờ Facebook PHẢI bị ép về false ở nhánh này, kể cả khi admin đang bật chế độ đó cho Shopee.
     * Để nguyên thì giao diện hứa "Mở Facebook ngay" trong khi phía sau không hề có bước đó.
     */
    public function test_nhanh_tiktok_khong_bao_gio_vong_qua_facebook(): void
    {
        ApiConfig::create([
            'name' => 'Facebook',
            'endpoint' => 'https://graph.facebook.com',
            'platform' => 'facebook',
            'app_id' => '111222',
            'app_secret' => 'page-token',
            'is_active' => true,
            'meta' => ['comment_redirect_enabled' => true, 'target_post_id' => '111222_333444', 'auto_redirect_enabled' => true],
        ]);
        $this->fakeChuoiThat();

        $this->dan(self::TIKTOK_SHORT)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('viaFacebookComment', false)
                ->where('autoRedirect', false)
            );
    }

    /** Mã khách gắn ngay lúc tạo link — link ACCESSTRADE đã cố định, sửa sau là phá chữ ký của họ. */
    public function test_ma_khach_duoc_gan_ngay_luc_tao_link(): void
    {
        $this->fakeChuoiThat();
        $user = User::factory()->create();
        $user->forceFill(['sub_id' => 'u7k2m9'])->save();

        $this->actingAs($user)->post('/voucher/resolve', ['url' => self::TIKTOK_SHORT], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) Mobile/15E148',
        ])->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'product_link/create')) {
                return false;
            }

            return $request->data()['sub1'] === 'u7k2m9' && $request->data()['utm_content'] === 'u7k2m9';
        });
    }

    /** Link TikTok KHÔNG phải link sản phẩm (video, trang shop): báo cho khách dán lại đúng thứ. */
    public function test_link_tiktok_khong_phai_san_pham_thi_bao_loi_ro_rang(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $this->dan('https://www.tiktok.com/@someshop/video/7300000000000000000')
            ->assertRedirect()
            ->assertSessionHasErrors('voucher_url');
    }

    /** Sàn không hỗ trợ: thông điệp phải nói ra sàn nào ĐƯỢC, không chỉ nói "không hợp lệ". */
    public function test_san_khong_ho_tro_thi_noi_ro_san_nao_duoc(): void
    {
        Http::fake();

        $this->dan('https://www.lazada.vn/products/abc-i123.html')
            ->assertRedirect()
            ->assertSessionHasErrors('voucher_url');

        $this->assertStringContainsString(
            'TikTok Shop',
            session('errors')->get('voucher_url')[0],
        );
    }

    /** Luồng Shopee không được đụng tới: cùng cửa vào, khác nhánh. */
    public function test_link_shopee_van_di_nhanh_cu(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $this->dan('https://shopee.vn/Ao-Hoodie-i.564687320.29261186260')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('voucherResult.platform', 'shopee'));
    }
}
