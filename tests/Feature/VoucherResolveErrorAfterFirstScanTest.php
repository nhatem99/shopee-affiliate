<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sau lần quét THÀNH CÔNG đầu tiên, URL của trang là /voucher/resolve (controller Inertia::render
 * thẳng từ POST), nên mọi lần quét sau trình duyệt gửi Referer = /voucher/resolve. Lỗi trả bằng
 * back() đi: POST → GET /voucher/resolve (redirect) → GET /, và flash `errors` bị tiêu ở hop
 * giữa. Trang chủ hiện ra như "thành công" mà không có kết quả, không toast — khách tưởng nút
 * Dán chết cho tới khi tải lại trang (lúc đó Referer về / và lỗi mới hiện).
 *
 * Test đi đúng đường trình duyệt đi (Referer + theo hết redirect) và đòi lỗi phải tới trang chủ.
 */
class VoucherResolveErrorAfterFirstScanTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE_URL_AFTER_FIRST_SCAN = '/voucher/resolve';

    /** Lỗi validate (chuỗi dán vào không phải link) đi qua back() mặc định của ValidationException. */
    public function test_validation_error_reaches_home_after_a_first_scan(): void
    {
        $this->actingAs($this->createAdmin())
            ->followingRedirects()
            ->from(self::PAGE_URL_AFTER_FIRST_SCAN)
            ->post('/voucher/resolve', ['url' => 'khong phai link'])
            ->assertInertia(fn ($page) => $page
                ->component('Home')
                ->has('errors.url'));
    }

    /** Lỗi nghiệp vụ (link không phải Shopee) do controller tự trả về. */
    public function test_business_error_reaches_home_after_a_first_scan(): void
    {
        $this->actingAs($this->createAdmin())
            ->followingRedirects()
            ->from(self::PAGE_URL_AFTER_FIRST_SCAN)
            ->post('/voucher/resolve', ['url' => 'https://www.lazada.vn/products/ao-hoodie-i123.html'])
            ->assertInertia(fn ($page) => $page
                ->component('Home')
                ->has('errors.voucher_url'));
    }

    /** Khách thường mở từ máy tính: lời từ chối "chỉ dùng trên điện thoại" cũng phải tới nơi. */
    public function test_desktop_block_message_reaches_home_after_a_first_scan(): void
    {
        $this->followingRedirects()
            ->from(self::PAGE_URL_AFTER_FIRST_SCAN)
            ->post('/voucher/resolve', ['url' => 'https://shopee.vn/Ao-Hoodie-i.564687320.29261186260'])
            ->assertInertia(fn ($page) => $page
                ->component('Home')
                ->has('errors.voucher_url'));
    }
}
