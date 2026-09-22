<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Hạng thành viên đang được hưởng. NULL = chưa qua lần xét nào, đọc ra là hạng thấp
            // nhất (xem MembershipTierService::definition). Cố ý KHÔNG lấp sẵn giá trị cho khách
            // cũ: hạng chỉ có nghĩa sau một lần xét theo quý, mà lần xét đó là việc của lệnh
            // tiers:refresh chứ không phải của migration.
            $table->string('tier', 20)->nullable()->after('last_seen_at');

            // Quý mà hạng ở trên được xét cho, dạng '2026-Q4'. Thiếu cột này thì lệnh xét hạng
            // không phân biệt nổi "đã xét cho quý này rồi" với "hạng còn sót lại từ quý trước",
            // nên hoặc ghi đè mỗi ngày hoặc không bao giờ ghi.
            $table->string('tier_quarter', 10)->nullable()->after('tier');

            $table->timestamp('tier_updated_at')->nullable()->after('tier_quarter');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['tier', 'tier_quarter', 'tier_updated_at']);
        });
    }
};
