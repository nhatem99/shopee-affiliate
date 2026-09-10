<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Mã định danh riêng của từng tài khoản, đi kèm link affiliate tới Shopee và quay về trong
     * cột Sub_id của báo cáo hoa hồng — đây là thứ DUY NHẤT nối được một đơn hàng có thật với
     * một khách hàng của mình, tức nền móng của chức năng hoàn tiền.
     *
     * nullable: tài khoản tạo trước bản deploy này được backfill ngay bên dưới, nhưng cột vẫn
     * để nullable để một lần ghi hỏng không làm sập việc đăng ký. unique: hai người trùng mã
     * là hoàn tiền sai người, phải để DB chặn chứ không tin vào code sinh mã.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sub_id', 32)->nullable()->unique()->after('wallet_balance');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['sub_id']);
            $table->dropColumn('sub_id');
        });
    }

    /**
     * Cách sinh mã cố tình được viết lại ở đây thay vì gọi User::generateSubId() — migration
     * phải đứng yên theo thời gian, còn hàm bên model sẽ đổi khi format mã đổi. Gọi model là
     * migration này chạy ra kết quả khác nhau tuỳ thời điểm ai chạy nó.
     */
    private function backfill(): void
    {
        $taken = [];

        DB::table('users')->whereNull('sub_id')->orderBy('id')->select('id')->chunkById(200, function ($users) use (&$taken) {
            foreach ($users as $user) {
                do {
                    $code = Str::lower(Str::random(10));
                } while (isset($taken[$code]) || DB::table('users')->where('sub_id', $code)->exists());

                $taken[$code] = true;

                DB::table('users')->where('id', $user->id)->update(['sub_id' => $code]);
            }
        });
    }
};
