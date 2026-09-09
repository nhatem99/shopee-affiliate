<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Trang theo dõi cho lọc theo IP và theo khoảng ngày — thêm index để khỏi quét cả bảng. */
    public function up(): void
    {
        Schema::table('user_activities', function (Blueprint $table) {
            $table->index(['ip_address', 'created_at'], 'user_activities_ip_created_index');
            $table->index('created_at', 'user_activities_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('user_activities', function (Blueprint $table) {
            $table->dropIndex('user_activities_ip_created_index');
            $table->dropIndex('user_activities_created_at_index');
        });
    }
};
