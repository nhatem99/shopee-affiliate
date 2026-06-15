<?php

namespace Database\Factories;

use App\Models\AffiliateLink;
use App\Models\Commission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Commission>
 */
class CommissionFactory extends Factory
{
    protected $model = Commission::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $status = fake()->randomElement(['pending', 'approved', 'approved', 'paid']);

        return [
            'user_id' => User::factory(),
            'affiliate_link_id' => AffiliateLink::factory(),
            'amount' => fake()->numberBetween(5, 300) * 1000,
            'status' => $status,
            'order_id' => fake()->boolean(70) ? 'ORD-'.fake()->unique()->numerify('########') : null,
            'confirmed_at' => in_array($status, ['approved', 'paid'], true) ? now() : null,
            'paid_at' => $status === 'paid' ? now() : null,
        ];
    }
}
