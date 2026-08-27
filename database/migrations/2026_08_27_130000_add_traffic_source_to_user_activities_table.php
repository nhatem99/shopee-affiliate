<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_activities', function (Blueprint $table) {
            // Nhãn nguồn khách đã chuẩn hoá (facebook/zalo/google/tiktok/direct/...) — dùng để
            // thống kê/lọc nhanh. Khác với cột `source` sẵn có (đó là nền tảng mã voucher khách bấm).
            $table->string('traffic_source', 30)->nullable()->after('city');
            $table->string('referrer_host', 191)->nullable()->after('traffic_source');
            $table->string('utm_source', 100)->nullable()->after('referrer_host');
            $table->string('utm_medium', 100)->nullable()->after('utm_source');
            $table->string('utm_campaign', 100)->nullable()->after('utm_medium');

            $table->index('traffic_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_activities', function (Blueprint $table) {
            $table->dropIndex(['traffic_source']);
            $table->dropColumn(['traffic_source', 'referrer_host', 'utm_source', 'utm_medium', 'utm_campaign']);
        });
    }
};
