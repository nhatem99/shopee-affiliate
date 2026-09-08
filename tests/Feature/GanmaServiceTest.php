<?php

namespace Tests\Feature;

use App\Services\GanmaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Nguồn mã YouTube. Khác kieushopee ở chỗ đây là API BẤT ĐỒNG BỘ: tạo job rồi hỏi lại tới khi
 * xong (~20 giây thật). Shape phản hồi trong các test này lấy nguyên từ số đo production
 * 08-09-2026, không phải bịa ra.
 */
class GanmaServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SHORT_URL = 'https://vn.shp.ee/r1MhnNPY';

    private const YOUTUBE_LINK = 'https://s.shopee.vn/an_redir?affiliate_id=17104820001&origin_link=https%3A%2F%2Fshopee.vn%2Fproduct%2F1541796848%2F27236640960%3Fgads_t_sig%3DCHUKY&sub_id=YT3-token';

    protected function setUp(): void
    {
        parent::setUp();

        // Không có dòng này thì mỗi test đứng chờ thật đúng bằng nhịp hỏi lại.
        Sleep::fake();

        config([
            'services.ganma.poll_interval_seconds' => 3,
            'services.ganma.max_wait_seconds' => 60,
        ]);
    }

    private function service(): GanmaService
    {
        return app(GanmaService::class);
    }

    private function statusBody(string $status, array $extra = []): array
    {
        return array_merge([
            'status' => $status,
            'youtube_link' => null,
            'error' => null,
            'shopee_url' => self::SHORT_URL,
            'queue_position' => 0,
            'product_info' => null,
        ], $extra);
    }

    /** Ca chuẩn: job xếp hàng, chạy, rồi ra link. */
    public function test_polls_until_the_job_completes_and_returns_the_link(): void
    {
        Http::fake([
            '*/yt/request-conversion' => Http::response(['job_id' => 'job-1', 'status' => 'pending']),
            '*/yt/check-status*' => Http::sequence()
                ->push($this->statusBody('pending', ['queue_position' => 3]))
                ->push($this->statusBody('processing'))
                ->push($this->statusBody('complete', [
                    'youtube_link' => self::YOUTUBE_LINK,
                    'product_info' => [
                        'product_name' => 'Loa Bluetooth JBL Flip 6',
                        'product_image' => 'https://cf.shopee.vn/file/vn-abc',
                        'product_price' => 547500,
                        'item_id' => 27236640960,
                    ],
                ])),
        ]);

        $result = $this->service()->fetchProductAndVoucherLink(self::SHORT_URL, useCache: false);

        $this->assertSame(self::YOUTUBE_LINK, $result['voucher_link']);
        $this->assertSame('Loa Bluetooth JBL Flip 6', $result['product']['product_name']);
        // shop_id không có trong product_info — phải moi ra từ origin_link bên trong an_redir,
        // vì phía sau cần cả cặp shop/item để hỏi Shopee khi thiếu thông tin sản phẩm.
        $this->assertSame('1541796848', $result['shop_id']);
        $this->assertSame('27236640960', $result['item_id']);
    }

    /**
     * 'error' là trạng thái KẾT THÚC. Hỏi tiếp chỉ tốn thêm thời gian chờ của khách trong khi
     * kết quả không bao giờ đổi.
     */
    public function test_stops_polling_immediately_on_a_business_error(): void
    {
        Http::fake([
            '*/yt/request-conversion' => Http::response(['job_id' => 'job-2', 'status' => 'pending']),
            '*/yt/check-status*' => Http::sequence()
                ->push($this->statusBody('processing'))
                ->push($this->statusBody('error', [
                    'error' => 'Shop bạn gửi không hỗ trợ mã, vui lòng tìm sản phẩm này trên shop khác và thử lại.',
                ]))
                // Hỏi thêm lần nữa là sai — sequence hết phần tử sẽ ném lỗi, tức test bắt được.
                ->pushStatus(500),
        ]);

        $this->assertNull($this->service()->fetchProductAndVoucherLink(self::SHORT_URL, useCache: false));
    }

    /** Lỗi đồng bộ ngay ở bước tạo job: HTTP 200 nhưng job_id = null. */
    public function test_returns_null_when_the_job_is_rejected_up_front(): void
    {
        Http::fake([
            '*/yt/request-conversion' => Http::response([
                'job_id' => null,
                'status' => 'error',
                'error' => '❌ Vui lòng sử dụng link sản phẩm từ ứng dụng Shopee.',
            ]),
        ]);

        $this->assertNull($this->service()->fetchProductAndVoucherLink(self::SHORT_URL, useCache: false));
    }

    /** Job treo mãi không xong thì phải bỏ cuộc, không giữ request của khách vô hạn. */
    public function test_gives_up_after_the_configured_wait(): void
    {
        config(['services.ganma.max_wait_seconds' => 9]);

        Http::fake([
            '*/yt/request-conversion' => Http::response(['job_id' => 'job-3', 'status' => 'pending']),
            '*/yt/check-status*' => Http::response($this->statusBody('processing')),
        ]);

        $this->assertNull($this->service()->fetchProductAndVoucherLink(self::SHORT_URL, useCache: false));

        // 9 giây chờ / nhịp 3 giây = đúng 3 lần hỏi, không phải một vòng lặp vô tận.
        Http::assertSentCount(4);
    }

    /**
     * Đo thật: ganma từ chối link shopee.vn đầy đủ ("Vui lòng sử dụng link sản phẩm từ ứng dụng
     * Shopee"). Chặn từ đây để khỏi đốt một round-trip 20 giây chỉ để nhận lỗi biết trước.
     */
    public function test_refuses_full_shopee_urls_without_calling_the_api(): void
    {
        Http::fake();

        $this->assertFalse($this->service()->canHandle('https://shopee.vn/product-i.1.2'));
        $this->assertNull($this->service()->fetchProductAndVoucherLink('https://shopee.vn/product-i.1.2', useCache: false));

        Http::assertNothingSent();
    }

    /** Cả ba domain link ngắn đều tạo được job — đo thật, không suy đoán. */
    public function test_accepts_every_shopee_app_share_domain(): void
    {
        foreach (['https://vn.shp.ee/abc', 'https://shope.ee/abc', 'https://s.shopee.vn/abc'] as $url) {
            $this->assertTrue($this->service()->canHandle($url), $url.' phải được chấp nhận');
        }
    }

    /**
     * Ganma không trả giá gốc. Thà hiện "giảm 0%" còn hơn bịa ra một con số — khách so giá với
     * Shopee thấy lệch là mất tin ngay.
     */
    public function test_does_not_invent_a_discount_it_was_never_given(): void
    {
        Http::fake([
            '*/yt/request-conversion' => Http::response(['job_id' => 'job-4', 'status' => 'pending']),
            '*/yt/check-status*' => Http::response($this->statusBody('complete', [
                'youtube_link' => self::YOUTUBE_LINK,
                'product_info' => ['product_name' => 'Loa JBL', 'product_price' => 547500, 'item_id' => 1],
            ])),
        ]);

        $product = $this->service()->fetchProductAndVoucherLink(self::SHORT_URL, useCache: false)['product'];

        $this->assertSame(0, $product['discount_percent']);
        $this->assertSame(547500.0, $product['original_price']);
        $this->assertSame(547500.0, $product['discounted_price']);
    }
}
