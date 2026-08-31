<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('short_links', function (Blueprint $table) {
            $table->string('product_image')->nullable()->after('product_name');
        });
    }

    public function down(): void
    {
        Schema::table('short_links', function (Blueprint $table) {
            $table->dropColumn('product_image');
        });
    }
};
