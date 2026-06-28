<?php

namespace Database\Seeders;

use App\Models\PlatformVoucher;
use Illuminate\Database\Seeder;

class PlatformVoucherSeeder extends Seeder
{
    /**
     * Tạo các mã giảm giá gợi ý (Facebook / YouTube) hiển thị ở trang chủ.
     */
    public function run(): void
    {
        $vouchers = [
            ['platform' => 'shopee', 'source' => 'facebook', 'code' => 'FBSHOPEE50', 'title' => 'Giảm 50K cho đơn từ 300K', 'discount_type' => 'flat', 'discount_value' => 50000, 'minimum_order' => 300000],
            ['platform' => 'shopee', 'source' => 'youtube', 'code' => 'YTSHOPEE15', 'title' => 'Giảm 15% tối đa 100K', 'discount_type' => 'percent', 'discount_value' => 15, 'minimum_order' => 0],
            ['platform' => 'shopee', 'source' => 'facebook', 'code' => 'FREESHIPXTRA', 'title' => 'Miễn phí vận chuyển toàn quốc', 'discount_type' => 'freeship', 'discount_value' => 0, 'minimum_order' => 0],
            ['platform' => 'lazada', 'source' => 'youtube', 'code' => 'YTLAZADA70', 'title' => 'Giảm 70K cho đơn từ 500K', 'discount_type' => 'flat', 'discount_value' => 70000, 'minimum_order' => 500000],
            ['platform' => 'lazada', 'source' => 'facebook', 'code' => 'FBLAZADA20', 'title' => 'Giảm 20% tối đa 150K', 'discount_type' => 'percent', 'discount_value' => 20, 'minimum_order' => 200000],
            ['platform' => 'tiki', 'source' => 'facebook', 'code' => 'FBTIKI30', 'title' => 'Giảm 30K cho đơn từ 250K', 'discount_type' => 'flat', 'discount_value' => 30000, 'minimum_order' => 250000],
            ['platform' => 'tiktok', 'source' => 'youtube', 'code' => 'YTTIKTOK10', 'title' => 'Giảm 10% cho khách mới', 'discount_type' => 'percent', 'discount_value' => 10, 'minimum_order' => 0],
            ['platform' => 'all', 'source' => 'facebook', 'code' => 'SANSALE25', 'title' => 'Giảm 25K áp dụng mọi sàn', 'discount_type' => 'flat', 'discount_value' => 25000, 'minimum_order' => 99000],
        ];

        foreach ($vouchers as $v) {
            PlatformVoucher::updateOrCreate(
                ['code' => $v['code']],
                array_merge($v, [
                    'is_active' => true,
                    'expires_at' => now()->addDays(fake()->numberBetween(7, 45)),
                ]),
            );
        }

        $this->command->info('Đã tạo '.count($vouchers).' mã giảm giá gợi ý.');
    }
}
