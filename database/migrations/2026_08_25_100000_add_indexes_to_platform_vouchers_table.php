<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_vouchers', function (Blueprint $table) {
            // Khớp query PlatformVoucher::active()->latest()->take(N):
            // where is_active = 1 and (expires_at is null or expires_at > now()) order by created_at desc
            $table->index(['is_active', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('platform_vouchers', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'created_at']);
            $table->dropIndex(['expires_at']);
        });
    }
};
