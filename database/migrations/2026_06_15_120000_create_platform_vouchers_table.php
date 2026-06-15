<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('platform')->default('shopee'); // shopee|lazada|tiki|tiktok|all
            $table->string('source')->default('manual');   // facebook|youtube|manual
            $table->string('code');
            $table->string('title')->nullable();
            $table->string('discount_type')->default('flat'); // flat|percent|freeship
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('minimum_order', 12, 2)->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_vouchers');
    }
};
