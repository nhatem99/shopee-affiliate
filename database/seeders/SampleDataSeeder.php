<?php

namespace Database\Seeders;

use App\Models\AffiliateLink;
use App\Models\Commission;
use App\Models\User;
use Illuminate\Database\Seeder;

class SampleDataSeeder extends Seeder
{
    /**
     * Tạo dữ liệu commission mẫu trải đều 30 ngày gần nhất
     * để xem biểu đồ "Lịch sử theo ngày" trên Admin Dashboard.
     */
    public function run(): void
    {
        // Lấy/khởi tạo vài user thường để gán đơn
        $users = User::where('role', 'user')->take(5)->get();
        if ($users->count() < 5) {
            $users = $users->merge(
                User::factory()->count(5 - $users->count())->create(['role' => 'user'])
            );
        }

        $total = 0;

        foreach (range(29, 0) as $i) {
            $day = now()->subDays($i);
            $count = fake()->numberBetween(2, 12);

            for ($j = 0; $j < $count; $j++) {
                $createdAt = $day->copy()->setTime(
                    fake()->numberBetween(8, 22),
                    fake()->numberBetween(0, 59)
                );
                $user = $users->random();

                $link = AffiliateLink::factory()->create([
                    'user_id' => $user->id,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                Commission::factory()->create([
                    'user_id' => $user->id,
                    'affiliate_link_id' => $link->id,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                $total++;
            }
        }

        $this->command->info("Đã tạo {$total} commission mẫu trải 30 ngày.");
    }
}
