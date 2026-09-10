<?php

namespace Tests\Feature;

use App\Models\ShopeeOrder;
use App\Services\ShopeeReportImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Đây là khâu quyết định ai được bao nhiêu tiền, nên fixture dùng ĐÚNG dòng tiêu đề của báo cáo
 * thật (Shopee xuất ngày 10-09-2026) chứ không phải tiêu đề tự nghĩ ra: dấu tiếng Việt, ký hiệu
 * ₫ trong ngoặc, cột trùng tiền tố ("Giá(₫)" vs "Giá trị đơn hàng (₫)") — đó mới là những chỗ
 * khâu đọc tiêu đề thật sự có thể gãy.
 */
class ShopeeReportImportTest extends TestCase
{
    use RefreshDatabase;

    private function csv(?string $body = null): string
    {
        $body ??= file_get_contents(base_path('tests/Fixtures/shopee-report.csv'));

        // Shopee xuất kèm BOM. Fixture lưu không BOM và gắn vào ở đây để việc cắt BOM luôn được
        // kiểm tra thật, chứ không phụ thuộc trình soạn thảo nào đó có giữ 3 byte đó hay không.
        $path = tempnam(sys_get_temp_dir(), 'shopee').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".$body);

        return $path;
    }

    private function import(?string $body = null): array
    {
        return app(ShopeeReportImportService::class)->import($this->csv($body));
    }

    public function test_imports_every_product_line_of_the_report(): void
    {
        $summary = $this->import();

        $this->assertSame(5, $summary['rows']);
        $this->assertSame(5, $summary['created']);
        $this->assertSame(3, ShopeeOrder::distinct()->count('order_id'));
    }

    /**
     * Đơn nhiều sản phẩm dồn hết hoa hồng vào dòng đầu, dòng sau bằng 0. Cộng theo đơn phải ra
     * đúng số của dòng đầu — cộng nhầm cột "Tổng hoa hồng sản phẩm" sẽ ra 84937.6 gấp đôi.
     */
    public function test_commission_is_read_from_the_net_affiliate_column(): void
    {
        $this->import();

        $lines = ShopeeOrder::where('order_id', '260909JA8XKJ9J')->orderBy('id')->get();

        $this->assertSame('84937.60', $lines[0]->net_commission);
        $this->assertSame('0.00', $lines[1]->net_commission);
        $this->assertEqualsWithDelta(
            84937.6,
            (float) ShopeeOrder::where('order_id', '260909JA8XKJ9J')->sum('net_commission'),
            0.001,
        );
    }

    /** Hai cột tên gần giống nhau không được lẫn vào nhau. */
    public function test_price_and_order_value_are_different_columns(): void
    {
        $this->import();

        $line = ShopeeOrder::where('item_id', '50400574093')->where('model_id', '405081466425')->first();

        $this->assertSame('254000.00', $line->price);
        $this->assertSame('772160.00', $line->order_value);
        $this->assertSame(4, $line->quantity);
    }

    public function test_maps_shopee_status_to_our_own(): void
    {
        $this->import();

        $this->assertSame('pending', ShopeeOrder::where('order_id', '260909JA8XKJ9J')->first()->status);
        $this->assertSame('completed', ShopeeOrder::where('order_id', '260827EWCNNXPJ')->first()->status);
        $this->assertSame('cancelled', ShopeeOrder::where('order_id', '260908FJF750P8')->first()->status);
    }

    /** Trạng thái lạ không được lặng lẽ thành 'completed' — tiền sẽ tự chảy ra ngoài. */
    public function test_unknown_status_falls_back_to_pending(): void
    {
        $body = str_replace('Hoàn thành,', 'Trạng thái mới toanh,', file_get_contents(base_path('tests/Fixtures/shopee-report.csv')));

        $this->import($body);

        $this->assertSame(0, ShopeeOrder::where('status', 'completed')->count());
    }

    // ── Quy đơn về khách ──────────────────────────────────────────────────────

    /** Dạng 1: Shopee KHÔNG tách khe, cả chuỗi 5 khe rơi vào Sub_id1. */
    public function test_extracts_user_code_from_an_unsplit_sub_id(): void
    {
        $this->import();

        $line = ShopeeOrder::where('order_id', '260909JA8XKJ9J')->first();

        $this->assertSame('fb-u7k2m9---', $line->sub_id_raw);
        $this->assertSame('u7k2m9', $line->user_sub_id);
    }

    /** Dạng 2: Shopee tách khe, mã nằm sẵn ở cột Sub_id2. */
    public function test_extracts_user_code_from_a_split_sub_id(): void
    {
        $this->import();

        $line = ShopeeOrder::where('order_id', '260827EWCNNXPJ')->first();

        $this->assertSame('u7k2m9', $line->user_sub_id);
    }

    /** Đơn cũ chỉ mang nhãn kênh: không có mã khách, và đó không phải lỗi. */
    public function test_channel_only_sub_id_yields_no_user_code(): void
    {
        $this->import();

        $line = ShopeeOrder::where('order_id', '260908FJF750P8')->first();

        $this->assertSame('tietkiemvi', $line->sub_id_raw);
        $this->assertNull($line->user_sub_id);
        $this->assertNull($line->user_id);
    }

    public function test_links_the_line_to_the_user_owning_that_sub_id(): void
    {
        $user = $this->createUser();
        $user->forceFill(['sub_id' => 'u7k2m9'])->save();

        $summary = $this->import();

        $this->assertSame(4, $summary['matched']);
        $this->assertSame(1, $summary['unmatched']);
        $this->assertSame(4, ShopeeOrder::where('user_id', $user->id)->count());
    }

    /** Mã không thuộc tài khoản nào vẫn phải nhập được, chỉ là chưa quy về ai. */
    public function test_unknown_user_code_is_stored_without_a_user(): void
    {
        $this->import();

        $line = ShopeeOrder::where('order_id', '260909JA8XKJ9J')->first();

        $this->assertSame('u7k2m9', $line->user_sub_id);
        $this->assertNull($line->user_id);
    }

    // ── Nhập lại ──────────────────────────────────────────────────────────────

    /**
     * Báo cáo được tải lại sau vài ngày sẽ mang đúng các dòng cũ kèm trạng thái đã đổi. Nhập lại
     * phải CẬP NHẬT chứ không nhân bản — nhân bản là nhân đôi tiền phải trả.
     */
    public function test_reimporting_the_same_report_updates_instead_of_duplicating(): void
    {
        $this->import();
        $summary = $this->import();

        $this->assertSame(0, $summary['created']);
        $this->assertSame(5, $summary['updated']);
        $this->assertSame(5, ShopeeOrder::count());
    }

    public function test_reimport_picks_up_a_status_change(): void
    {
        $this->import();

        $body = str_replace('Đang chờ xử lý', 'Hoàn thành', file_get_contents(base_path('tests/Fixtures/shopee-report.csv')));
        $this->import($body);

        $this->assertSame('completed', ShopeeOrder::where('order_id', '260909JA8XKJ9J')->first()->status);
    }

    /**
     * Hai sản phẩm khác nhau trong cùng một đơn phải là hai dòng. Khoá chống trùng thiếu item_id
     * thì dòng sau đè lên dòng trước và đơn mất một nửa doanh thu.
     */
    public function test_two_products_of_one_order_stay_two_rows(): void
    {
        $this->import();

        $this->assertSame(2, ShopeeOrder::where('order_id', '260827EWCNNXPJ')->count());
    }

    // ── Hỏng thì phải kêu ─────────────────────────────────────────────────────

    /**
     * Nhầm file (báo cáo khác, hoặc Shopee đổi tên cột) phải dừng hẳn kèm thông báo đọc được.
     * Nhập im lặng ra 0 dòng là kiểu hỏng tệ nhất ở đây: nhìn như "kỳ này không có đơn".
     */
    public function test_a_file_that_is_not_the_commission_report_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Thiếu cột/');

        $this->import("Ngày,Số tiền\n2026-09-01,1000\n");
    }

    public function test_the_error_lists_the_columns_it_did_find(): void
    {
        try {
            $this->import("Ngày,Số tiền\n2026-09-01,1000\n");
            $this->fail('Đáng lẽ phải ném RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ngay', $e->getMessage());
            $this->assertStringContainsString('so tien', $e->getMessage());
        }
    }
}
