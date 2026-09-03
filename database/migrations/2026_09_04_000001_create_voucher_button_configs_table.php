<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_button_configs', function (Blueprint $table) {
            $table->id();
            // One row per platform source — values are fixed: facebook, instagram, zalo, youtube.
            $table->string('source')->unique();
            // When non-null, this label ALWAYS overrides every entry from this source,
            // including real per-product API labels (e.g. "Mã FB 22%"). This is intentional:
            // the admin wants full control over the display name, not just the fallback.
            $table->string('label')->nullable();
            // Lower number = appears first in the button grid.
            $table->unsignedInteger('sort_order')->default(0);
            // The first entry (by final render order) of the featured source gets the
            // "Đề xuất" badge + animate-pulse-ring glow. Only one source should be featured
            // at a time; the controller enforces this (radio-group behaviour on update).
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_button_configs');
    }
};
