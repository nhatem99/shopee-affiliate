<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nút "Lấy danh sách page" (FacebookPageListSync): bot đọc trang "Trang của bạn" trên Facebook.
     * Page có ở /admin/fb-groups mà không còn trong danh sách đó (đã xoá, mất quyền quản trị) thì bị
     * tắt và ghi lúc phát hiện vào missing_since — không xoá để giữ lịch sử bài đã đăng.
     */
    public function up(): void
    {
        Schema::table('facebook_profiles', function (Blueprint $table) {
            $table->timestamp('missing_since')->nullable()->after('blocked_reason');
        });
    }

    public function down(): void
    {
        Schema::table('facebook_profiles', function (Blueprint $table) {
            $table->dropColumn('missing_since');
        });
    }
};
