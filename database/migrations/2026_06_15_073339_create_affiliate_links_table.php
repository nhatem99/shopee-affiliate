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
        Schema::create('affiliate_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('original_url');
            $table->string('short_url')->nullable();
            $table->enum('platform', ['shopee', 'lazada', 'tiki', 'tiktok']);
            $table->string('product_name')->nullable();
            $table->text('product_image')->nullable();
            $table->decimal('original_price', 15, 2)->nullable();
            $table->decimal('discounted_price', 15, 2)->nullable();
            $table->tinyInteger('discount_percent')->nullable();
            $table->integer('sold_count')->default(0);
            $table->decimal('rating', 3, 2)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index('platform');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_links');
    }
};
