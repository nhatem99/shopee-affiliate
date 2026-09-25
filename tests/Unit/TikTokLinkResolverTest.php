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

    /**
     * Chuỗi redirect THẬT, chép từ link chia sẻ lấy trong app TikTok ngày 25-09-2026:
     * vt.tiktok.com/ZS9A4R76bVmje-pJ8pO → 301 → shop.tiktok.com/vn/pdp/{id}?_d=...&chain_key=...
     *
     * Dạng /vn/pdp/ này chính là chỗ bản đầu tiên của service sai: regex chỉ biết
     * /view/product/ (dạng feed của ACCESSTRADE trả về) nên mọi link khách dán đều ra null.
     */
    public function test_link_rut_gon_that_tu_app_duoc_giai(): void
    {
        Http::fake([
            'vt.tiktok.com/*' => Http::response('', 301, [
                'Location' => 'https://shop.tiktok.com/vn/pdp/'.self::PRODUCT_ID.'?_d=e7j35hbmmll668&_svg=1&chain_key=EzPRbF0aqpyq',
            ]),
        ]);

        $result = $this->resolver()->resolve('https://vt.tiktok.com/ZS9A4R76bVmje-pJ8pO/');

        $this->assertSame(self::PRODUCT_ID, $result['product_id']);
        // Tham số của link chia sẻ bị cắt sạch: chain_key/_d là tracking của người đã chia sẻ.
        $this->assertSame('https://shop.tiktok.com/vn/pdp/'.self::PRODUCT_ID, $result['canonical_url']);
    }

    /** Thị trường khác cho ra /th/pdp/, /id/pdp/... — chặn cứng "vn" là rơi hết mấy link đó. */
    public function test_nhan_ca_tien_to_quoc_gia_khac(): void
    {
        Http::fake();

        $result = $this->resolver()->resolve('https://shop.tiktok.com/th/pdp/'.self::PRODUCT_ID);

        $this->assertSame(self::PRODUCT_ID, $result['product_id']);
    }

    /** Dạng cũ /view/product/ vẫn phải nhận: đó là dạng feed của ACCESSTRADE trả về. */
    public function test_van_nhan_dang_view_product_cua_feed(): void
    {
        Http::fake([
            'vt.tiktok.com/*' => Http::response('', 302, [
                'Location' => 'https://shop.tiktok.com/view/product/'.self::PRODUCT_ID.'?region=VN',
            ]),
        ]);

        $result = $this->resolver()->resolve('https://vt.tiktok.com/ZSABC123/');

        $this->assertSame(self::PRODUCT_ID, $result['product_id']);
        $this->assertSame('https://shop.tiktok.com/vn/pdp/'.self::PRODUCT_ID, $result['canonical_url']);
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
