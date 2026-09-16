<?php

namespace Tests\Feature;

use App\Models\ShopeeOrder;
use App\Models\UserActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nối cú bấm link (short_link_click) với đơn trong báo cáo hoa hồng Shopee — xem
 * ClickOrderMatchService.
 */
class ClickOrderMatchTest extends TestCase
{
    use RefreshDatabase;

    private function click(string $product, string $when): UserActivity
    {
        $a = UserActivity::create([
            'event_type' => 'short_link_click',
            'product_name' => $product,
            'source' => 'kieushopee',
            'ip_address' => '1.1.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $a->forceFill(['created_at' => $when, 'updated_at' => $when])->save();

        return $a;
    }

    private function order(string $product, string $clickedAt, array $extra = []): ShopeeOrder
    {
        static $n = 0;
        $n++;

        return ShopeeOrder::create(array_merge([
            'order_id' => 'ORD'.$n,
            'item_id' => 'ITEM'.$n,
            'product_name' => $product,
            'net_commission' => 10000,
            'status' => 'completed',
            'order_status_raw' => 'Hoàn thành',
            'clicked_at' => $clickedAt,
            'ordered_at' => $clickedAt,
        ], $extra));
    }

    public function test_click_is_matched_to_order_of_the_same_product(): void
    {
        $click = $this->click('Dép quai ngang', '2026-09-15 10:00:00');
        // Shopee ghi mốc click ở cấp đơn nên lệch vài giây tới vài phút là bình thường.
        $this->order('DÉP QUAI  NGANG', '2026-09-15 10:05:00', ['net_commission' => 12500]);

        $this->actingAs($this->createAdmin())
            ->get('/admin/activities?from=2026-09-15&to=2026-09-15')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activities.data.0.id', $click->id)
                ->where('activities.data.0.order.commission', 12500)
                ->where('activities.data.0.order.status', 'completed')
                ->where('activities.data.0.order.cross_sell', false)
                ->where('summary.conversions.orders', 1)
                ->where('summary.conversions.commission', 12500)
                ->where('summary.conversions.unmatched_orders', 0)
                ->where('summary.conversions.products.0.orders', 1)
                ->where('summary.conversions.products.0.commission', 12500)
            );
    }

    public function test_order_long_after_the_click_is_not_matched(): void
    {
        $this->click('Dép quai ngang', '2026-09-15 10:00:00');
        $this->order('Dép quai ngang', '2026-09-15 14:00:00');

        $this->actingAs($this->createAdmin())
            ->get('/admin/activities?from=2026-09-15&to=2026-09-15')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activities.data.0.order', null)
                ->where('summary.conversions.orders', 0)
                ->where('summary.conversions.unmatched_orders', 1)
            );
    }

    public function test_customer_buying_another_product_right_after_the_click_is_flagged(): void
    {
        $this->click('Dép quai ngang', '2026-09-15 10:00:00');
        $this->order('Nước giặt Ariel', '2026-09-15 10:00:30');

        $this->actingAs($this->createAdmin())
            ->get('/admin/activities?from=2026-09-15&to=2026-09-15')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activities.data.0.order.cross_sell', true)
                ->where('activities.data.0.order.product_name', 'Nước giặt Ariel')
                // Đơn vẫn được quy về sản phẩm của CÚ CLICK, không phải sản phẩm trên đơn.
                ->where('summary.conversions.products.0.name', 'Dép quai ngang')
                ->where('summary.conversions.products.0.orders', 1)
            );
    }

    public function test_one_click_is_not_counted_for_two_orders(): void
    {
        $this->click('Dép quai ngang', '2026-09-15 10:00:00');
        $this->order('Dép quai ngang', '2026-09-15 10:01:00');
        $this->order('Dép quai ngang', '2026-09-15 10:02:00');

        $this->actingAs($this->createAdmin())
            ->get('/admin/activities?from=2026-09-15&to=2026-09-15')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.conversions.orders', 1)
                ->where('summary.conversions.unmatched_orders', 1)
            );
    }

    public function test_lines_of_the_same_order_count_as_one_order_with_summed_commission(): void
    {
        $this->click('Dép quai ngang', '2026-09-15 10:00:00');
        // Đơn nhiều sản phẩm: Shopee dồn hoa hồng cấp đơn vào dòng đầu, dòng sau bằng 0.
        $this->order('Dép quai ngang', '2026-09-15 10:01:00', ['order_id' => 'SAME', 'net_commission' => 20000]);
        $this->order('Áo thun kèm theo', '2026-09-15 10:01:00', ['order_id' => 'SAME', 'net_commission' => 0]);

        $this->actingAs($this->createAdmin())
            ->get('/admin/activities?from=2026-09-15&to=2026-09-15')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.conversions.orders', 1)
                ->where('summary.conversions.commission', 20000)
                ->where('summary.conversions.unmatched_orders', 0)
            );
    }
}
