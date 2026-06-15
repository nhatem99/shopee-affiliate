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
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_link_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->enum('discount_type', ['flat', 'percent', 'freeship']);
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('minimum_order', 15, 2)->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_freeship')->default(false);
            $table->string('source', 30)->default('shopee');
            $table->timestamps();
            $table->index('affiliate_link_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
