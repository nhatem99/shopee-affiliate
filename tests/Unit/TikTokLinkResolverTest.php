<?php

namespace Tests\Unit;

use App\Services\TikTokLinkResolverService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Giải link TikTok Shop về id sản phẩm (TikTokLinkResolverService).
 *
 * Điểm đắt nhất nếu sai: đưa thẳng link rút gọn cho ACCESSTRADE thì họ vẫn tạo link bình thường
 * (đo thật: họ bọc nguyên văn chuỗi nhận được, không kiểm tra), nên lỗi loại này KHÔNG bao giờ
 * báo ra — chỉ tới kỳ đối soát mới thấy đơn không quy về ai.
 */
class TikTokLinkResolverTest extends TestCase
{
    private const PRODUCT_ID = '1733724538346374522';

    private function resolver(): TikTokLinkResolverService
    {
        return app(TikTokLinkResolverService::class);
    }

    public function test_link_san_pham_day_du_thi_khong_can_goi_mang(): void
    {
        Http::fake();

        $result = $this->resolver()->resolve('https://shop.tiktok.com/view/product/'.self::PRODUCT_ID.'?region=VN&local=en');

        $this->assertSame(self::PRODUCT_ID, $result['product_id']);
        Http::assertNothingSent();
    }

    /** Dạng www.tiktok.com/view/product/... — ACCESSTRADE trả về dạng này trong feed. */
    public function test_nhan_ca_dang_tren_ten_mien_tiktok_com(): void
    {
        Http::fake();

        $result = $this->resolver()->resolve('https://www.tiktok.com/view/product/'.self::PRODUCT_ID);

        $this->assertSame(self::PRODUCT_ID, $result['product_id']);
    }

    /** Link rút gọn từ app: phải đi theo redirect mới thấy id. */
    public function test_link_rut_gon_duoc_giai_qua_redirect(): void
    {
        Http::fake([
            'vt.tiktok.com/*' => Http::response('', 302, [
                'Location' => 'https://shop.tiktok.com/view/product/'.self::PRODUCT_ID.'?region=VN',
            ]),
        ]);

        $result = $this->resolver()->resolve('https://vt.tiktok.com/ZSABC123/');

        $this->assertSame(self::PRODUCT_ID, $result['product_id']);
        $this->assertSame(
            'https://shop.tiktok.com/view/product/'.self::PRODUCT_ID.'?region=VN&local=vi',
            $result['canonical_url'],
        );
    }

    /** TikTok có trả Location tương đối — ghép sai là vòng sau mất luôn host rồi bỏ cuộc. */
    public function test_redirect_tuong_doi_duoc_ghep_lai_dung_host(): void
    {
        Http::fake([
            'vm.tiktok.com/*' => Http::response('', 301, ['Location' => '/view/product/'.self::PRODUCT_ID]),
        ]);

        $result = $this->resolver()->resolve('https://vm.tiktok.com/ZSABC123/');

        $this->assertSame(self::PRODUCT_ID, $result['product_id']);
    }

    /** Dạng tham số ?product_id= mà app gắn khi chia sẻ. */
    public function test_nhan_dang_tham_so_product_id(): void
    {
        Http::fake();

        $result = $this->resolver()->resolve('https://shop.tiktok.com/pdp?product_id='.self::PRODUCT_ID.'&from=share');

        $this->assertSame(self::PRODUCT_ID, $result['product_id']);
    }

    /** Link video/trang shop không phải link sản phẩm: trả null để nơi gọi báo cho khách dán lại. */
    public function test_link_khong_phai_san_pham_thi_tra_null(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $this->assertNull($this->resolver()->resolve('https://www.tiktok.com/@someshop/video/7300000000000000000'));
    }

    /** Không đuổi theo domain lạ: redirect ra ngoài TikTok là dừng, dù chưa thấy id. */
    public function test_khong_di_theo_domain_ngoai_tiktok(): void
    {
        Http::fake([
            'vt.tiktok.com/*' => Http::response('', 302, ['Location' => 'https://dau-do-la.example.com/view/product/999999999']),
        ]);

        $this->assertNull($this->resolver()->resolve('https://vt.tiktok.com/ZSABC123/'));
    }

    public function test_link_khong_phai_tiktok_thi_tra_null_ngay(): void
    {
        Http::fake();

        $this->assertNull($this->resolver()->resolve('https://shopee.vn/Ao-Hoodie-i.564687320.29261186260'));
        Http::assertNothingSent();
    }

    /** Vòng redirect vô tận không được làm treo request của khách. */
    public function test_vong_redirect_lap_vo_tan_thi_bo_cuoc(): void
    {
        Http::fake([
            'vt.tiktok.com/*' => Http::response('', 302, ['Location' => 'https://vt.tiktok.com/ZSLOOP/']),
        ]);

        $this->assertNull($this->resolver()->resolve('https://vt.tiktok.com/ZSABC123/'));
    }
}
