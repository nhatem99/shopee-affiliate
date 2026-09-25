<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Models\FacebookReelSlot;
use App\Models\User;
use App\Services\AffiliateLinkRewriterService;
use App\Services\FacebookReelSlotService;
use App\Services\KieuShopeeService;
use App\Services\VoucherRefService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hai khách khác nhau KHÔNG được dùng chung một reel (hoặc một comment) cho cùng một sản phẩm.
 *
 * Vì sao đây là lỗi tiền chứ không phải lỗi hiển thị: caption reel chứa short-link của người
 * thuê, và short-link đó mang Sub_id của người đó (AffiliateLinkRewriterService::buildSubId).
 * Dùng chung nghĩa là khách B mua bằng mã của khách A → báo cáo affiliate ghi đơn cho A →
 * CashbackService cộng tiền vào ví A. Đơn về đủ, hoa hồng về đủ, không có gì báo lỗi — chỉ là
 * B không bao giờ được hoàn tiền và không đời nào biết vì sao.
 *
 * Trước khi sửa, nhánh $held trong reelUrlForLocked() chỉ so product_key nên mọi khách cùng sản
 * phẩm đều nhận chung một reel trong suốt thời gian thuê.
 */
class FacebookSubIdIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** Link đích thật sự nằm trong caption: utm_content = "fb-<mã khách>---". */
    private function linkOf(string $customerCode): string
    {
        return 'https://shopee.vn/product/1/2?mmp_pid=an_123&utm_content=fb-'.$customerCode.'---';
    }

    private function service(array $reels): FacebookReelSlotService
    {
        return new FacebookReelSlotService(ApiConfig::create([
            'name' => 'Facebook',
            'endpoint' => 'https://graph.facebook.com',
            'platform' => 'facebook',
            'app_id' => '111222',
            'app_secret' => 'page-token',
            'is_active' => true,
            'meta' => [
                'comment_redirect_enabled' => true,
                'reel_caption_enabled' => true,
                'target_reel_ids' => $reels,
                'reel_lease_minutes' => 10,
            ],
        ]));
    }

    // --- Đọc ngược mã khách ra khỏi link ---

    public function test_doc_duoc_ma_khach_tu_link_da_gan_sub_id(): void
    {
        $this->assertSame('u7k2m9', AffiliateLinkRewriterService::customerCodeFrom($this->linkOf('u7k2m9')));
    }

    /** Khách vãng lai: link chỉ có nhãn kênh, không có khe mã khách. */
    public function test_link_cua_khach_vang_lai_khong_co_ma(): void
    {
        $this->assertNull(AffiliateLinkRewriterService::customerCodeFrom('https://shopee.vn/product/1/2?utm_content=fb'));
        $this->assertNull(AffiliateLinkRewriterService::customerCodeFrom('https://shopee.vn/product/1/2'));
        $this->assertNull(AffiliateLinkRewriterService::customerCodeFrom(null));
    }

    // --- Cách ly reel ---

    /** Ranh giới chính: cùng sản phẩm, hai khách khác nhau → hai reel khác nhau. */
    public function test_hai_khach_cung_san_pham_khong_dung_chung_reel(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $service = $this->service([
            'https://www.facebook.com/reel/111',
            'https://www.facebook.com/reel/222',
        ]);

        $reelA = $service->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/aaa', 'u7k2m9');
        $reelB = $service->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/bbb', 'ze3lfu');

        $this->assertNotNull($reelA);
        $this->assertNotNull($reelB);
        $this->assertNotSame($reelA, $reelB, 'Khách B đang được đưa tới reel mang link của khách A — tiền hoàn sẽ về ví A.');
    }

    /** Cùng một khách bấm lại thì vẫn dùng lại reel cũ — không đốt thêm slot vô ích. */
    public function test_cung_mot_khach_bam_lai_thi_dung_lai_reel_cu(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $service = $this->service([
            'https://www.facebook.com/reel/111',
            'https://www.facebook.com/reel/222',
        ]);

        $lan1 = $service->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/aaa', 'u7k2m9');
        $lan2 = $service->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/ccc', 'u7k2m9');

        $this->assertSame($lan1, $lan2);
    }

    /** Khách vãng lai dùng chung được với nhau: link của họ không mang mã ai cả. */
    public function test_khach_vang_lai_van_dung_chung_duoc(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $service = $this->service([
            'https://www.facebook.com/reel/111',
            'https://www.facebook.com/reel/222',
        ]);

        $lan1 = $service->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/aaa', null);
        $lan2 = $service->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/bbb', null);

        $this->assertSame($lan1, $lan2);
    }

    /** Thuê xong phải ghi lại mã khách, nếu không lượt sau lại coi reel là "của khách vãng lai". */
    public function test_ghi_lai_ma_khach_vao_slot(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        $this->service(['https://www.facebook.com/reel/111'])
            ->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/aaa', 'u7k2m9');

        $this->assertSame('u7k2m9', FacebookReelSlot::where('reel_id', '111')->value('user_sub_id'));
    }

    /**
     * Hết slot thì trả null để khách đi thẳng Shopee bằng CHÍNH link của mình — hỏng theo hướng
     * mất bước vòng qua Facebook, chứ không phải theo hướng trả tiền cho nhầm người.
     */
    public function test_het_slot_thi_tra_null_chu_khong_muon_reel_cua_khach_khac(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $service = $this->service(['https://www.facebook.com/reel/111']);

        $service->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/aaa', 'u7k2m9');
        $reelB = $service->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/bbb', 'ze3lfu');

        $this->assertNull($reelB);
    }

    // --- Cách ly comment (đường dự phòng khi không bật chế độ reel) ---

    /**
     * Hai khách cùng sản phẩm phải ra HAI comment khác nhau. Trước khi sửa, khoá cache chỉ gồm
     * nguồn + sản phẩm nên khách thứ hai trúng cache và nhận permalink comment của khách thứ
     * nhất — trong comment đó là short-link mang Sub_id của người thứ nhất.
     */
    public function test_hai_khach_cung_san_pham_khong_dung_chung_comment(): void
    {
        Cache::flush();
        ApiConfig::create([
            'name' => 'Facebook',
            'endpoint' => 'https://graph.facebook.com',
            'platform' => 'facebook',
            'app_id' => '111222',
            'app_secret' => 'page-token',
            'is_active' => true,
            'meta' => ['comment_redirect_enabled' => true, 'target_post_id' => '111222_333444'],
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response([
            'id' => '111222_333444_999',
            'permalink_url' => 'https://www.facebook.com/permalink',
        ])]);

        // utm_content phải có sẵn trong link nguồn thì mã khách mới được gắn vào —
        // xem AffiliateLinkRewriterService::swapMmpPid().
        $url = 'https://shopee.vn/san-pham-i.1.2?mmp_pid=kieushopee&utm_content=----';

        foreach (['u7k2m9', 'ze3lfu'] as $subId) {
            $user = User::factory()->create();
            $user->forceFill(['sub_id' => $subId])->save();

            $this->actingAs($user)
                ->postJson('/voucher/shorten', [
                    'ref' => app(VoucherRefService::class)->issue($url, KieuShopeeService::SOURCE),
                    'product_name' => 'Áo Hoodie',
                ])
                ->assertOk();
        }

        $daDang = 0;
        Http::recorded(function ($request) use (&$daDang) {
            if (str_contains($request->url(), '/comments')) {
                $daDang++;
            }
        });

        $this->assertSame(2, $daDang, 'Khách thứ hai đang dùng lại comment của khách thứ nhất — trong đó là link mang Sub_id của người kia.');
    }

    /**
     * Bản ghi cũ (tạo trước khi có cột user_sub_id) không được biến thành reel dùng chung: nó
     * đang hiện link của một khách cụ thể mà ô mã lại trống.
     */
    public function test_slot_cu_thieu_ma_khach_khong_bi_khach_khac_muon(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $service = $this->service([
            'https://www.facebook.com/reel/111',
            'https://www.facebook.com/reel/222',
        ]);

        // Slot kiểu cũ: đang thuê cho sản phẩm, còn hạn, nhưng không ghi mã khách.
        FacebookReelSlot::create([
            'reel_id' => '111',
            'product_key' => 'ao-hoodie',
            'product_name' => 'Áo Hoodie',
            'target_url' => 'https://tietkiemvi.com/go/cu',
            'leased_until' => now()->addMinutes(9),
        ]);

        $reel = $service->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/moi', 'u7k2m9');

        $this->assertSame('https://www.facebook.com/reel/222', $reel);
    }
}
