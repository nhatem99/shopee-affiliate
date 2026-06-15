<?php

namespace Database\Seeders;

use App\Models\ApiConfig;
use Illuminate\Database\Seeder;

class ApiConfigSeeder extends Seeder
{
    public function run(): void
    {
        ApiConfig::firstOrCreate(
            ['platform' => 'shopee'],
            [
                'name' => 'Shopee Open API',
                'endpoint' => 'https://partner.shopeemobile.com/api/v2',
                'app_id' => 'YOUR_SHOPEE_PARTNER_ID',
                'app_secret' => 'YOUR_SHOPEE_PARTNER_KEY',
                'is_active' => false,
            ]
        );

        ApiConfig::firstOrCreate(
            ['platform' => 'accesstrade'],
            [
                'name' => 'AccessTrade',
                'endpoint' => 'https://api.accesstrade.vn/v1',
                'app_id' => 'YOUR_ACCESSTRADE_PUBLISHER_ID',
                'app_secret' => 'YOUR_ACCESSTRADE_API_KEY',
                'is_active' => false,
            ]
        );
    }
}
