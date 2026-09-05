<?php

namespace Database\Seeders;

use App\Models\VoucherButtonConfig;
use Illuminate\Database\Seeder;

class VoucherButtonConfigSeeder extends Seeder
{
    public function run(): void
    {
        // Default order mirrors the channel order in config services.channel_voucher.channels.
        // Facebook is featured by default — its first entry currently gets the "Đề xuất"
        // badge in Home.vue (the first entry overall). Labels are null = use API/fallback label.
        $defaults = [
            ['source' => 'facebook',  'label' => null, 'sort_order' => 0, 'is_featured' => true],
            ['source' => 'instagram', 'label' => null, 'sort_order' => 1, 'is_featured' => false],
            ['source' => 'zalo',      'label' => null, 'sort_order' => 2, 'is_featured' => false],
            ['source' => 'youtube',   'label' => null, 'sort_order' => 3, 'is_featured' => false],
        ];

        // firstOrCreate, not updateOrCreate: re-running the seeder on an existing
        // install must not wipe labels/order the admin already customised.
        foreach ($defaults as $row) {
            VoucherButtonConfig::firstOrCreate(
                ['source' => $row['source']],
                $row,
            );
        }
    }
}
