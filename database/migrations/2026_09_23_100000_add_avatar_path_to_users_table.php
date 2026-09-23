<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Đường dẫn TƯƠNG ĐỐI trên disk 'uploads' (vd: avatars/2026/09/abc.jpg), không phải
            // URL đầy đủ: APP_URL đổi (dev ↔ prod, http ↔ https) là mọi URL lưu cứng hỏng hết,
            // còn đường dẫn tương đối thì Storage::url() tự ghép lại đúng theo môi trường.
            //
            // NULL = chưa tải ảnh nào, giao diện quay về chữ cái đầu của tên như trước.
            $table->string('avatar_path')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
