<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill the four default rows.
     *
     * The deploy pipeline only runs `migrate --force` (never `db:seed`), so
     * VoucherButtonConfigSeeder never ran in production and the table stayed
     * empty — leaving /admin/voucher-buttons with nothing to render.
     * Values are inlined rather than read from the seeder so this migration
     * stays frozen in time.
     */
    private const DEFAULTS = [
        ['source' => 'facebook',  'label' => null, 'sort_order' => 0, 'is_featured' => true],
        ['source' => 'instagram', 'label' => null, 'sort_order' => 1, 'is_featured' => false],
        ['source' => 'zalo',      'label' => null, 'sort_order' => 2, 'is_featured' => false],
        ['source' => 'youtube',   'label' => null, 'sort_order' => 3, 'is_featured' => false],
    ];

    public function up(): void
    {
        $now = now();

        // insertOrIgnore + the unique `source` index makes this a no-op on any
        // environment where the seeder already created the rows.
        DB::table('voucher_button_configs')->insertOrIgnore(
            array_map(
                fn (array $row) => $row + ['created_at' => $now, 'updated_at' => $now],
                self::DEFAULTS,
            ),
        );
    }

    public function down(): void
    {
        DB::table('voucher_button_configs')
            ->whereIn('source', array_column(self::DEFAULTS, 'source'))
            ->delete();
    }
};
