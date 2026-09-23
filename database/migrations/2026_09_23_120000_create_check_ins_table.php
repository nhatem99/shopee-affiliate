<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sổ điểm danh hằng ngày. Tiền thưởng vẫn ghi vào bảng commissions (type 'checkin') như mọi
     * khoản khác — bảng này KHÔNG giữ tiền, nó giữ ba thứ mà commissions không diễn đạt được:
     *
     *  1. NGÀY điểm danh dưới dạng cột date theo giờ Việt Nam. Suy ngược từ created_at của
     *     commission là phải so sánh mốc nửa đêm trong câu truy vấn mỗi lần, và lệch múi giờ
     *     một tiếng là khách mất/được thêm một lượt.
     *  2. Ràng buộc unique(user_id, checked_on) — đây mới là thứ chặn điểm danh hai lần một
     *     ngày khi khách bấm nút liên tiếp, chứ không phải câu if trong PHP.
     *  3. Chuỗi ngày liên tiếp tại thời điểm điểm danh. Tính lại từ lịch sử thì mỗi lần đọc
     *     phải quét cả bảng của khách đó và vẫn ra sai nếu sau này đổi luật tính chuỗi.
     */
    public function up(): void
    {
        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Ngày theo giờ Việt Nam (config app.timezone), KHÔNG phải UTC.
            $table->date('checked_on');

            // Phần quà bốc được, tách khỏi thưởng mốc chuỗi: kho quà mỗi ngày đếm tồn theo đúng
            // cột này ("Còn N phần quà"). Cộng chung một cột thì một lượt trúng mốc ngày 7 sẽ
            // bị đếm nhầm thành một phần quà mệnh giá lạ, và kho lệch từ đó.
            $table->decimal('prize_amount', 15, 2);

            // Thưởng thêm khi chuỗi chạm mốc (7 ngày / 30 ngày), 0 với ngày thường.
            $table->decimal('bonus_amount', 15, 2)->default(0);

            // prize_amount + bonus_amount — số thật sự vào ví, chép sẵn để trang lịch sử không
            // phải cộng lại.
            $table->decimal('amount', 15, 2);

            $table->unsignedInteger('streak')->default(1);

            $table->timestamps();

            $table->unique(['user_id', 'checked_on']);
            // Kho quà trong ngày: đếm tồn theo (ngày, mệnh giá) ở mỗi lượt bấm nút.
            $table->index(['checked_on', 'prize_amount']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_ins');
    }
};
