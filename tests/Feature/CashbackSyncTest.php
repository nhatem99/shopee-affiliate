<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Setting;
use App\Models\ShopeeOrder;
use App\Models\User;
use App\Services\CashbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Khâu duy nhất biến "đơn hàng trong báo cáo" thành "tiền khách rút được", nên mọi đường đi sai
 * ở đây đều là mất tiền thật. Thứ tự ưu tiên của các test dưới đây theo đúng mức thiệt hại:
 * trả tiền cho đơn chưa hoàn thành > trả trùng > không thu hồi đơn huỷ.
 */
class CashbackSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();
        Setting::set(CashbackService::RATE_KEY, '50');
    }

    private function line(array $attributes = []): ShopeeOrder
    {
        static $n = 0;
        $n++;

        return ShopeeOrder::create(array_merge([
            'order_id' => 'ORDER'.$n,
            'item_id' => 'ITEM'.$n,
            'model_id' => '',
            'status' => 'completed',
            'net_commission' => 10000,
            'user_id' => $this->user->id,
            'user_sub_id' => $this->user->sub_id,
        ], $attributes));
    }

    private function sync(): array
    {
        return app(CashbackService::class)->sync();
    }

    public function test_completed_order_becomes_an_approved_commission(): void
    {
        $this->line(['order_id' => 'A', 'net_commission' => 10000]);

        $summary = $this->sync();

        $commission = Commission::where('order_id', 'A')->first();

        $this->assertSame(1, $summary['created']);
        $this->assertSame('5000.00', $commission->amount);
        $this->assertSame('approved', $commission->status);
        $this->assertSame($this->user->id, $commission->user_id);
    }

    /**
     * Cửa chặn quan trọng nhất: đơn chưa hoàn thành vẫn còn huỷ được, mà 'approved' là rút được
     * tiền ngay qua availableBalance().
     */
    public function test_pending_order_pays_nothing(): void
    {
        $this->line(['order_id' => 'A', 'status' => 'pending']);

        $this->sync();

        $this->assertSame(0, Commission::count());
    }

    public function test_cancelled_order_pays_nothing(): void
    {
        $this->line(['order_id' => 'A', 'status' => 'cancelled']);

        $this->sync();

        $this->assertSame(0, Commission::count());
    }

    /** Đơn không quy được về ai thì không có ví nào để cộng vào. */
    public function test_order_without_a_user_pays_nothing(): void
    {
        $this->line(['order_id' => 'A', 'user_id' => null, 'user_sub_id' => null]);

        $this->sync();

        $this->assertSame(0, Commission::count());
    }

    /**
     * Đơn nhiều sản phẩm: Shopee dồn hoa hồng vào dòng đầu, dòng sau bằng 0. Phải cộng theo đơn,
     * và chỉ ra ĐÚNG MỘT bản ghi hoa hồng.
     */
    public function test_multi_product_order_is_summed_into_one_commission(): void
    {
        $this->line(['order_id' => 'A', 'item_id' => 'I1', 'net_commission' => 84937.60]);
        $this->line(['order_id' => 'A', 'item_id' => 'I2', 'net_commission' => 0]);

        $this->sync();

        $this->assertSame(1, Commission::where('order_id', 'A')->count());
        $this->assertSame('42468.80', Commission::where('order_id', 'A')->first()->amount);
    }

    /** Chạy lại sau mỗi lần nhập báo cáo là chuyện thường — không được cộng dồn thêm lần nữa. */
    public function test_running_twice_does_not_pay_twice(): void
    {
        $this->line(['order_id' => 'A', 'net_commission' => 10000]);

        $this->sync();
        $summary = $this->sync();

        $this->assertSame(0, $summary['created']);
        $this->assertSame(0, $summary['updated']);
        $this->assertSame(1, Commission::count());
        $this->assertSame('5000.00', Commission::first()->amount);
    }

    /** Shopee chỉnh lại hoa hồng (đổi trả một phần) thì số tiền phải đi theo. */
    public function test_a_changed_commission_amount_is_updated(): void
    {
        $line = $this->line(['order_id' => 'A', 'net_commission' => 10000]);
        $this->sync();

        $line->update(['net_commission' => 6000]);
        $summary = $this->sync();

        $this->assertSame(1, $summary['updated']);
        $this->assertSame('3000.00', Commission::where('order_id', 'A')->first()->amount);
    }

    // ── Thu hồi ───────────────────────────────────────────────────────────────

    public function test_an_order_cancelled_later_has_its_commission_revoked(): void
    {
        $line = $this->line(['order_id' => 'A']);
        $this->sync();
        $this->assertSame(1, Commission::count());

        $line->update(['status' => 'cancelled']);
        $summary = $this->sync();

        $this->assertSame(1, $summary['revoked']);
        $this->assertSame(0, Commission::count());
    }

    /** Tiền đã chi trả thì không đòi lại được — giữ bản ghi để sổ sách khớp với thực tế. */
    public function test_already_paid_commission_is_not_revoked(): void
    {
        $line = $this->line(['order_id' => 'A']);
        $this->sync();
        Commission::where('order_id', 'A')->update(['status' => 'paid', 'paid_at' => now()]);

        $line->update(['status' => 'cancelled']);
        $summary = $this->sync();

        $this->assertSame(0, $summary['revoked']);
        $this->assertSame(1, Commission::count());
    }

    /** Thu hồi phải chạy kể cả khi admin đã hạ tỉ lệ về 0 để tạm dừng chương trình. */
    public function test_revoking_works_even_with_the_rate_turned_off(): void
    {
        $line = $this->line(['order_id' => 'A']);
        $this->sync();

        $line->update(['status' => 'cancelled']);
        Setting::set(CashbackService::RATE_KEY, '0');
        $summary = $this->sync();

        $this->assertSame(1, $summary['revoked']);
        $this->assertSame(0, Commission::count());
    }

    // ── Tỉ lệ ─────────────────────────────────────────────────────────────────

    /** Chưa cấu hình tỉ lệ = chưa trả đồng nào, thay vì lặng lẽ trả 0đ cho mọi người. */
    public function test_no_rate_configured_pays_nothing(): void
    {
        Setting::set(CashbackService::RATE_KEY, '0');
        $this->line(['order_id' => 'A']);

        $summary = $this->sync();

        $this->assertSame(0, $summary['created']);
        $this->assertSame(0, Commission::count());
    }

    public function test_rate_comes_from_settings(): void
    {
        Setting::set(CashbackService::RATE_KEY, '30');
        $this->line(['order_id' => 'A', 'net_commission' => 10000]);

        $this->sync();

        $this->assertSame('3000.00', Commission::where('order_id', 'A')->first()->amount);
    }

    /** Tiền đã duyệt phải hiện ra ở đúng chỗ khách bấm rút. */
    public function test_approved_cashback_shows_up_as_withdrawable_balance(): void
    {
        $this->line(['order_id' => 'A', 'net_commission' => 10000]);

        $this->sync();

        $this->assertEqualsWithDelta(5000.0, $this->user->fresh()->availableBalance(), 0.001);
    }
}
