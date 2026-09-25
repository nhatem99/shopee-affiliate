<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Models\Commission;
use App\Models\Setting;
use App\Models\ShopeeOrder;
use App\Models\User;
use App\Services\AccessTradeOrderImportService;
use App\Services\AccessTradeService;
use App\Services\CashbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Kéo đơn TikTok Shop từ ACCESSTRADE về ví khách (AccessTradeOrderImportService).
 *
 * Đây là khâu đụng thẳng vào tiền thật, nên mấy ranh giới dưới đây đáng khoá bằng test hơn là
 * đáng tin vào việc đọc code: đơn chưa đối soát xong thì TUYỆT ĐỐI chưa được cộng tiền, đơn bị
 * từ chối phải thành 'cancelled' để tiền được thu hồi, và đơn của hai sàn không được đè nhau.
 *
 * Hình dạng response chép từ tài liệu order-list v2 của họ.
 */
class AccessTradeOrderImportTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://api.accesstrade.vn/v1/order-list*';

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

    /** @param array<int, array<string, mixed>> $orders */
    private function fakeOrders(array $orders): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => $orders, 'total' => count($orders)])]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function order(array $overrides = []): array
    {
        return array_merge([
            'order_id' => '220602QAVKQKCM',
            'billing' => 690000.0,
            'pub_commission' => 22080.0,
            'products_count' => 1,
            'is_confirmed' => 1,
            'order_pending' => 0,
            'order_approved' => 1,
            'order_reject' => 0,
            'sales_time' => '2026-09-20T10:00:00',
            'confirmed_time' => '2026-09-24T23:59:31',
            'click_time' => '2026-09-20T09:55:00',
            'merchant' => 'tiktokshop',
            'utm_content' => 'u7k2m9',
        ], $overrides);
    }

    /** sub_id được model tự sinh lúc tạo (User::booted), nên phải ghi đè để khớp utm_content trong test. */
    private function customer(string $subId = 'u7k2m9'): User
    {
        $user = User::factory()->create();
        $user->forceFill(['sub_id' => $subId])->save();

        return $user;
    }

    private function import(int $days = 30): array
    {
        return app(AccessTradeOrderImportService::class)->import($days);
    }

    public function test_don_da_doi_soat_xong_thi_ghi_va_quy_ve_dung_khach(): void
    {
        $user = $this->customer();
        $this->fakeOrders([$this->order()]);

        $summary = $this->import();

        $this->assertSame(1, $summary['fetched']);
        $this->assertSame(1, $summary['matched']);

        $order = ShopeeOrder::where('platform', ShopeeOrder::PLATFORM_TIKTOK)->firstOrFail();
        $this->assertSame('completed', $order->status);
        $this->assertSame($user->id, $order->user_id);
        $this->assertSame('u7k2m9', $order->user_sub_id);
        $this->assertSame('22080.00', $order->net_commission);
    }

    /**
     * Ranh giới đắt nhất: ACCESSTRADE đánh dấu approved nhưng CHƯA đối soát (is_confirmed = 0).
     * Đơn đó còn lật về reject được — cộng tiền lúc này là trả cho khách bằng tiền chưa về.
     */
    public function test_approved_nhung_chua_doi_soat_thi_van_la_dang_cho(): void
    {
        $this->customer();
        $this->fakeOrders([$this->order(['is_confirmed' => 0])]);

        $this->import();

        $this->assertSame('pending', ShopeeOrder::firstOrFail()->status);
    }

    public function test_don_con_mon_dang_cho_thi_chua_hoan_thanh(): void
    {
        $this->customer();
        $this->fakeOrders([$this->order(['order_pending' => 1, 'order_approved' => 1])]);

        $this->import();

        $this->assertSame('pending', ShopeeOrder::firstOrFail()->status);
    }

    public function test_don_bi_tu_choi_thanh_cancelled(): void
    {
        $this->customer();
        $this->fakeOrders([$this->order(['order_approved' => 0, 'order_reject' => 1, 'is_confirmed' => 1])]);

        $this->import();

        $this->assertSame('cancelled', ShopeeOrder::firstOrFail()->status);
    }

    /** Đơn hoàn thành phải chảy tiếp vào ví khách ngay trong cùng lượt chạy. */
    public function test_don_hoan_thanh_thi_tien_vao_vi_khach(): void
    {
        $user = $this->customer();
        Setting::set(CashbackService::RATE_KEY, '40');
        $this->fakeOrders([$this->order()]);

        $this->import();

        $commission = Commission::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('approved', $commission->status);
        $this->assertGreaterThan(0, (float) $commission->amount);
    }

    /** Khách vãng lai (không có utm_content): vẫn ghi đơn để đối soát, chỉ là không của ai. */
    public function test_don_khong_co_ma_khach_van_duoc_ghi_lai(): void
    {
        $this->fakeOrders([$this->order(['utm_content' => ''])]);

        $summary = $this->import();

        $this->assertSame(1, $summary['unmatched']);
        $this->assertNull(ShopeeOrder::firstOrFail()->user_id);
    }

    /** Chuỗi 5 khe kiểu Shopee cũng phải đọc được, phòng khi ô utm_content dùng chung. */
    public function test_doc_duoc_ca_dang_chuoi_5_khe(): void
    {
        $user = $this->customer('u7k2m9');
        $this->fakeOrders([$this->order(['utm_content' => 'fb-u7k2m9---'])]);

        $this->import();

        $this->assertSame($user->id, ShopeeOrder::firstOrFail()->user_id);
    }

    /** Chạy lại cùng khoảng thời gian là cập nhật, không nhân bản đơn. */
    public function test_chay_lai_khong_tao_don_trung(): void
    {
        $this->customer();

        // Một stub duy nhất đổi dữ liệu theo biến: gọi Http::fake() lần hai KHÔNG thay được
        // stub cũ — Laravel giữ cả hai và cái đăng ký trước vẫn khớp trước, nên lượt chạy sau
        // sẽ nhận lại đúng dữ liệu cũ và test thành ra không kiểm tra gì cả.
        // Closure đầy đủ + use (&$confirmed): arrow function bắt biến theo GIÁ TRỊ lúc tạo, nên
        // đổi $confirmed sau đó không tới được bên trong.
        $confirmed = 0;
        Http::fake(function () use (&$confirmed) {
            return Http::response([
                'data' => [$this->order(['is_confirmed' => $confirmed])],
                'total' => 1,
            ]);
        });

        $this->import();

        $confirmed = 1;
        $summary = $this->import();

        $this->assertSame(1, ShopeeOrder::count());
        $this->assertSame(1, $summary['updated']);
        $this->assertSame('completed', ShopeeOrder::firstOrFail()->status);
    }

    /** Đơn Shopee trùng order_id không được bị đơn TikTok ghi đè — hai sàn đánh số riêng. */
    public function test_don_shopee_trung_so_khong_bi_de(): void
    {
        $shopee = ShopeeOrder::create([
            'platform' => ShopeeOrder::PLATFORM_SHOPEE,
            'order_id' => '220602QAVKQKCM',
            'item_id' => '123',
            'model_id' => '',
            'net_commission' => 5000,
            'status' => 'completed',
        ]);
        $this->customer();
        $this->fakeOrders([$this->order()]);

        $this->import();

        $this->assertSame(2, ShopeeOrder::count());
        $this->assertSame('5000.00', $shopee->fresh()->net_commission);
    }

    /** Khoảng dài hơn 31 ngày phải tự cắt — API của họ trả 500 nếu gửi nguyên. */
    public function test_khoang_dai_duoc_cat_thanh_nhieu_lat(): void
    {
        $this->fakeOrders([]);

        $summary = $this->import(90);

        $this->assertSame(3, $summary['windows']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'order-list')
            && $request->hasHeader('Authorization', 'Token khoa-test'));
    }

    /** API lỗi giữa chừng: dừng lát đó, không nuốt lỗi thành "không có đơn nào". */
    public function test_api_loi_thi_khong_ghi_gi_ca(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['message' => 'Unauthorized'], 401)]);

        $summary = $this->import(30);

        $this->assertSame(0, $summary['fetched']);
        $this->assertSame(0, ShopeeOrder::count());
    }
}
