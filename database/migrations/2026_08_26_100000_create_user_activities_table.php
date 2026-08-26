<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id', 100)->nullable();
            $table->string('event_type', 40);
            $table->text('url')->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('product_name')->nullable();
            $table->string('voucher_code', 50)->nullable();
            $table->string('source', 30)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_type', 20)->nullable();
            $table->string('browser', 50)->nullable();
            $table->string('os_name', 50)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'created_at']);
            $table->index('session_id');
            $table->index('platform');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activities');
    }
};
