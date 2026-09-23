<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kho sản phẩm Flash Sale hiển thị ở /flashsale — đồng bộ định kỳ từ nguồn ngoài
 * (xem FlashSaleSyncService). Khác /ma-giam-gia ở một điểm quan trọng: `claim_url` ở
 * đây do CHÍNH MÌNH dựng từ shopid+itemid, không lấy nguyên link của nguồn — xem
 * docblock của FlashSaleSyncService để biết vì sao.
 *
 * Nguồn trả TOÀN BỘ danh sách trong MỘT lần gọi (không phân trang), nên mỗi lượt đồng
 * bộ là một bức ảnh chụp đầy đủ. Sản phẩm không còn xuất hiện ở lượt chụp mới nhất bị
 * DỌN (xem FlashSaleSyncService::pruneStale) — khác voucher (giữ tới khi hết hạn),
 * flash sale không có mốc hết hạn rõ ràng nên "không còn trong lượt đồng bộ mới nhất"
 * chính là tín hiệu duy nhất biết suất đó đã đóng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_sale_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopid');
            $table->unsignedBigInteger('itemid');
            // Khung giờ trong ngày, dạng "09:00" — nguồn không kèm ngày, xem docblock
            // FlashSaleSyncService cho giả định về "hôm nay".
            $table->string('time_slot', 5);
            $table->string('title', 255);
            $table->text('image')->nullable();
            // VNĐ không có phần thập phân — lưu số nguyên, khớp kiểu nguồn trả về.
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('original_price')->nullable();
            $table->unsignedTinyInteger('percent')->default(0);
            // Số suất còn lại. <= 0 bị loại ngay ở bước đồng bộ (xem toRow()), nên mọi
            // dòng còn trong bảng đều có amount > 0 — cột này chỉ để hiển thị số liệu.
            $table->unsignedInteger('amount')->default(0);
            $table->text('claim_url');
            // Nhãn của LƯỢT ĐỒNG BỘ đã ghi ra dòng này — pruneStale() xoá mọi dòng không
            // mang đúng nhãn của lượt VỪA CHẠY. Dùng nhãn rời thay vì so sánh `synced_at`
            // theo thời gian: `Connection::prepareBindings()` format DateTimeInterface qua
            // upsert() bằng 'Y-m-d H:i:s' (KHÔNG có micro giây, thử thật đã thấy) — hai lượt
            // đồng bộ trong cùng một giây (dễ gặp khi test) sẽ có synced_at trùng hệt nhau,
            // pruneStale so sánh "<" sẽ không phân biệt được lượt nào cũ hơn.
            $table->string('sync_batch', 36);
            $table->timestamp('synced_at')->index();
            $table->timestamps();

            // Một sản phẩm có thể lên sale ở NHIỀU khung giờ khác nhau trong ngày (đo thật
            // 23-09-2026: 316/2659 dòng là cùng itemid khác time) — khoá theo cả ba để mỗi
            // khung giờ là một dòng riêng, upsert không đè nhầm suất này lên suất khác.
            $table->unique(['shopid', 'itemid', 'time_slot']);
            // Truy vấn chính của trang: lọc theo khung giờ, sắp theo % giảm.
            $table->index(['time_slot', 'percent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sale_items');
    }
};
