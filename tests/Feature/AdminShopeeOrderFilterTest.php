<?php

namespace Tests\Feature;

use App\Models\ShopeeOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lọc theo ngày/tháng ở /admin/shopee-orders.
 *
 * Chỗ dễ sai nhất không phải bộ lọc mà là mấy ô THỐNG KÊ: nếu chúng đếm toàn thời gian trong khi
 * bảng bên dưới chỉ hiện một tháng thì admin đối chiếu với trang Shopee sẽ thấy lệch mà không
 * hiểu vì sao — nên phần lớn test dưới đây khoá chuyện thống kê đi theo đúng kỳ đang xem.
 */
class AdminShopeeOrderFilterTest extends TestCase
{
    use RefreshDatabase;

    private function line(string $orderId, string $orderedAt, array $attributes = []): ShopeeOrder
    {
        return ShopeeOrder::create(array_merge([
            'order_id' => $orderId,
            'item_id' => 'I'.$orderId,
            'model_id' => '',
            'status' => 'completed',
            'net_commission' => 10000,
            'ordered_at' => $orderedAt,
        ], $attributes));
    }

    private function page(array $query = [])
    {
        return $this->actingAs($this->createAdmin())->get('/admin/shopee-orders?'.http_build_query($query));
    }

    private function rows($response): array
    {
        return $response->viewData('page')['props']['orders']['data'];
    }

    private function stats($response): array
    {
        return $response->viewData('page')['props']['stats'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->line('AUG', '2026-08-15 10:00:00');
        $this->line('SEP01', '2026-09-01 10:00:00');
        $this->line('SEP30', '2026-09-30 23:30:00');
        $this->line('OCT', '2026-10-02 10:00:00');
    }

    public function test_without_a_filter_everything_shows(): void
    {
        $this->assertCount(4, $this->rows($this->page()));
    }

    /** Chọn cả tháng 9: phải lấy trọn từ ngày 1 tới ngày 30, không hụt đầu hay cuối tháng. */
    public function test_a_whole_month_includes_its_first_and_last_day(): void
    {
        $rows = $this->rows($this->page(['from' => '2026-09-01', 'to' => '2026-09-30']));

        $this->assertEqualsCanonicalizing(['SEP01', 'SEP30'], array_column($rows, 'order_id'));
    }

    /**
     * Đơn lúc 23:30 ngày cuối kỳ vẫn phải nằm trong kỳ. Dùng so sánh thẳng datetime với '2026-09-30'
     * (tức 00:00) sẽ cắt mất đúng những đơn này — cả một ngày biến mất khỏi báo cáo.
     */
    public function test_the_last_day_is_inclusive_of_its_whole_day(): void
    {
        $rows = $this->rows($this->page(['to' => '2026-09-30']));

        $this->assertContains('SEP30', array_column($rows, 'order_id'));
    }

    public function test_filtering_a_single_day(): void
    {
        $rows = $this->rows($this->page(['from' => '2026-09-01', 'to' => '2026-09-01']));

        $this->assertSame(['SEP01'], array_column($rows, 'order_id'));
    }

    public function test_only_from_is_an_open_ended_range(): void
    {
        $rows = $this->rows($this->page(['from' => '2026-09-30']));

        $this->assertEqualsCanonicalizing(['SEP30', 'OCT'], array_column($rows, 'order_id'));
    }

    // ── Thống kê đi theo kỳ ───────────────────────────────────────────────────

    public function test_stats_follow_the_selected_range(): void
    {
        $stats = $this->stats($this->page(['from' => '2026-09-01', 'to' => '2026-09-30']));

        $this->assertSame(2, $stats['orders']);
        $this->assertEqualsWithDelta(20000.0, $stats['completed_commission'], 0.001);
    }

    /**
     * Thống kê KHÔNG được bó theo trạng thái đang lọc: nó vốn để chia nhỏ theo trạng thái, lọc sẵn
     * thì các ô còn lại luôn bằng 0 và nhìn như dữ liệu bị mất.
     */
    public function test_stats_are_not_narrowed_by_the_status_filter(): void
    {
        $this->line('SEPX', '2026-09-10 10:00:00', ['status' => 'cancelled']);

        $response = $this->page(['from' => '2026-09-01', 'to' => '2026-09-30', 'status' => 'completed']);

        $this->assertCount(2, $this->rows($response));
        $this->assertSame(1, $this->stats($response)['cancelled_orders']);
    }

    // ── Kết hợp bộ lọc ────────────────────────────────────────────────────────

    public function test_status_and_date_filters_combine(): void
    {
        $this->line('SEPC', '2026-09-12 10:00:00', ['status' => 'cancelled']);

        $rows = $this->rows($this->page([
            'from' => '2026-09-01',
            'to' => '2026-09-30',
            'status' => 'cancelled',
        ]));

        $this->assertSame(['SEPC'], array_column($rows, 'order_id'));
    }

    /** Bộ lọc phải quay lại trong props, nếu không giao diện quên mất mình đang xem kỳ nào. */
    public function test_active_filters_come_back_to_the_page(): void
    {
        $filters = $this->page(['from' => '2026-09-01', 'to' => '2026-09-30', 'status' => 'completed'])
            ->viewData('page')['props']['filters'];

        $this->assertSame('2026-09-01', $filters['from']);
        $this->assertSame('2026-09-30', $filters['to']);
        $this->assertSame('completed', $filters['status']);
    }

    // ── Đầu vào rác ───────────────────────────────────────────────────────────

    /**
     * '2026-02-31' đúng dạng nhưng không tồn tại. MySQL coi là ngày rỗng và trả về 0 dòng — admin
     * sẽ tưởng kỳ đó không có đơn thay vì biết mình gõ sai. Bỏ qua bộ lọc hỏng là lựa chọn ít gây
     * hiểu nhầm nhất.
     */
    public function test_an_impossible_date_is_ignored_rather_than_hiding_everything(): void
    {
        $response = $this->page(['from' => '2026-02-31']);

        $this->assertCount(4, $this->rows($response));
        $this->assertArrayNotHasKey('from', $response->viewData('page')['props']['filters']);
    }

    public function test_garbage_date_is_ignored(): void
    {
        $this->assertCount(4, $this->rows($this->page(['from' => 'hom-qua', 'to' => '<script>'])));
    }

    public function test_unknown_status_is_ignored(): void
    {
        $this->assertCount(4, $this->rows($this->page(['status' => 'linh-tinh'])));
    }
}
