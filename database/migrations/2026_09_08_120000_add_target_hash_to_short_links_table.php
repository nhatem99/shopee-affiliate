<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cột băm của target_url để tra cứu nhanh "đã có short-link nào trỏ tới đúng URL này chưa"
 * — xem ShortLinkService::create.
 *
 * Phải là cột riêng chứ không index thẳng target_url: đó là cột `text`, MySQL không cho index
 * nguyên cột (giới hạn độ dài khoá), mà URL voucher của Shopee thì dài hàng nghìn ký tự.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('short_links', function (Blueprint $table) {
            $table->string('target_hash', 40)->nullable()->after('target_url')->index();
        });

        // Backfill bằng PHP chứ không dùng hàm SHA1() của DB — SQLite (dev) không có sẵn hàm đó.
        DB::table('short_links')->select('id', 'target_url')->orderBy('id')->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('short_links')->where('id', $row->id)->update(['target_hash' => sha1($row->target_url)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('short_links', function (Blueprint $table) {
            $table->dropIndex(['target_hash']);
            $table->dropColumn('target_hash');
        });
    }
};
