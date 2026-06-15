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
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->string('role', 20)->default('user')->after('phone');
            $table->decimal('wallet_balance', 15, 2)->default(0)->after('role');
            $table->string('otp', 10)->nullable()->after('wallet_balance');
            $table->timestamp('otp_expires_at')->nullable()->after('otp');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'role', 'wallet_balance', 'otp', 'otp_expires_at']);
        });
    }
};
