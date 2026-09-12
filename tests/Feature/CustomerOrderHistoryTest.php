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
 * Trang này là nơi khách đối chiếu tiền của TỪNG đơn với số dư ở ví. Sai một con số ở đây là
 * khách tin rằng mình bị quỵt — nên phần lớn test dưới đây canh đúng một việc: số hiển thị phải
 * lấy từ bảng commissions khi có, và mọi số suy ra từ tỉ lệ đều phải được gắn nhãn "dự kiến".
 */
class CustomerOrderHistoryTest extends TestCase
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
            'ordered_at' => now()->subDays($n),
        ], $attributes));
    }

    private function commission(string $orderId, string $status, float $amount = 5000): Commission
    {
        return Commission::create([
            'user_id' => $this->user->id,
            'affiliate_link_id' => null,
            'order_id' => $orderId,
            'amount' => $amount,
            'status' => $status,
            'confirmed_at' => now(),
        ]);
    }

    // ── Quyền truy cập ────────────────────────────────────────────────────────

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/don-hang')->assertRedirect('/login');
    }

    public function test_admin_cannot_open_customer_page(): void
    {
        $this->actingAs($this->createAdmin())->get('/don-hang')->assertForbidden();
    }

    public function test_customer_only_sees_own_orders(): void
    {
        $other = $this->createUser();
        $this->line(['order_id' => 'CUA-TOI']);
        $this->line(['order_id' => 'CUA-NGUOI-KHAC', 'user_id' => $other->id, 'user_sub_id' => $other->sub_id]);

        $this->actingAs($this->user)
            ->get('/don-hang')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders')
                ->has('orders.data', 1)
                ->where('orders.data.0.order_id', 'CUA-TOI'));
    }

    // ── Tiền của từng đơn ─────────────────────────────────────────────────────

    public function test_credited_order_shows_the_commission_amount_not_an_estimate(): void
    {
        $this->line(['order_id' => 'A', 'net_commission' => 10000]);
        // Cố ý lệch với 50% của 10000: số hiển thị phải là số trong sổ tiền, không phải số tính lại.
        $this->commission('A', 'approved', 4321);

        $this->actingAs($this->user)
            ->get('/don-hang')
            ->assertInertia(fn ($page) => $page
                ->where('orders.data.0.status', 'credited')
                ->where('orders.data.0.amount', fn ($v) => (float) $v === 4321.0)
                ->where('orders.data.0.is_estimate', false));
    }

    public function test_paid_order_is_labelled_as_withdrawn(): void
    {
        $this->line(['order_id' => 'A']);
        $this->commission('A', 'paid', 5000);

        $this->actingAs($this->user)
            ->get('/don-hang')
            ->assertInertia(fn ($page) => $page->where('orders.data.0.status', 'paid'));
    }

    public function test_pending_order_shows_an_estimate_from_the_rate(): void
    {
        $this->line(['order_id' => 'A', 'status' => 'pending', 'net_commission' => 10000]);

        $this->actingAs($this->user)
            ->get('/don-hang')
            ->assertInertia(fn ($page) => $page
                ->where('orders.data.0.status', 'waiting')
                ->where('orders.data.0.amount', fn ($v) => (float) $v === 5000.0)
                ->where('orders.data.0.is_estimate', true));
    }

    public function test_completed_order_without_commission_is_marked_as_reconciling(): void
    {
        $this->line(['order_id' => 'A', 'status' => 'completed']);

        $this->actingAs($this->user)
            ->get('/don-hang')
            ->assertInertia(fn ($page) => $page->where('orders.data.0.status', 'reconciling'));
    }

    public function test_cancelled_order_is_shown_with_zero_instead_of_being_hidden(): void
    {
        // Đơn huỷ phải hiện ra: CashbackService::revokeCancelled() xoá hẳn bản ghi hoa hồng, nên
        // nếu giấu đơn đi thì số dư tụt xuống mà khách không có chỗ nào tra ra lý do.
        $this->line(['order_id' => 'A', 'status' => 'cancelled', 'net_commission' => 10000]);

        $this->actingAs($this->user)
            ->get('/don-hang')
            ->assertInertia(fn ($page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.status', 'cancelled')
                ->where('orders.data.0.amount', fn ($v) => (float) $v === 0.0));
    }

    public function test_rate_of_zero_shows_no_number_instead_of_promising_zero_dong(): void
    {
        Setting::set(CashbackService::RATE_KEY, '0');
        $this->line(['order_id' => 'A', 'status' => 'pending']);

        $this->actingAs($this->user)
            ->get('/don-hang')
            ->assertInertia(fn ($page) => $page
                ->where('orders.data.0.amount', null)
                ->where('summary.pending_estimate', null));
    }

    // ── Gộp dòng thành đơn ────────────────────────────────────────────────────

    public function test_multi_item_order_becomes_one_row_with_summed_commission(): void
    {
        // Shopee dồn hoa hồng cấp đơn vào dòng đầu và để dòng sau bằng 0 — gộp theo dòng thay vì
        // theo đơn là ra số sai và thừa hàng.
        $this->line(['order_id' => 'A', 'item_id' => 'I1', 'net_commission' => 8000, 'product_name' => 'Ao thun']);
        $this->line(['order_id' => 'A', 'item_id' => 'I2', 'net_commission' => 2000, 'product_name' => 'Quan dui']);
        $this->line(['order_id' => 'A', 'item_id' => 'I3', 'net_commission' => 0, 'product_name' => 'Vo sen']);

        $this->actingAs($this->user)
            ->get('/don-hang')
            ->assertInertia(fn ($page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.other_items', 2)
                // 50% của (8000 + 2000 + 0)
                ->where('orders.data.0.amount', fn ($v) => (float) $v === 5000.0));
    }

    public function test_order_with_one_cancelled_line_counts_as_cancelled(): void
    {
        // Khớp với CashbackService::revokeCancelled(), vốn thu hồi tiền theo cả đơn khi có dòng huỷ.
        $this->line(['order_id' => 'A', 'item_id' => 'I1', 'status' => 'completed']);
        $this->line(['order_id' => 'A', 'item_id' => 'I2', 'status' => 'cancelled']);

        $this->actingAs($this->user)
            ->get('/don-hang')
            ->assertInertia(fn ($page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.status', 'cancelled'));
    }

    // ── Tổng quan ─────────────────────────────────────────────────────────────

    public function test_summary_matches_the_wallet_and_counts_pending_and_cancelled(): void
    {
        $this->line(['order_id' => 'A', 'status' => 'completed', 'net_commission' => 10000]);
        $this->commission('A', 'approved', 5000);
        $this->line(['order_id' => 'B', 'status' => 'pending', 'net_commission' => 20000]);
        $this->line(['order_id' => 'C', 'status' => 'cancelled', 'net_commission' => 30000]);

        $this->actingAs($this->user)
            ->get('/don-hang')
            ->assertInertia(fn ($page) => $page
                // Cùng nguồn với số "Đã duyệt" ở trang Tài khoản — hai trang không được nói khác nhau.
                ->where('summary.credited', fn ($v) => (float) $v === 5000.0)
                ->where('summary.pending_estimate', fn ($v) => (float) $v === 10000.0)
                ->where('summary.cancelled_count', 1));
    }

    public function test_empty_state_does_not_break(): void
    {
        $this->actingAs($this->user)
            ->get('/don-hang')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('orders.data', 0)
                ->where('summary.credited', fn ($v) => (float) $v === 0.0));
    }
}
