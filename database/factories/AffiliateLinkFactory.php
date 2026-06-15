<?php

namespace Database\Factories;

use App\Models\AffiliateLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AffiliateLink>
 */
class AffiliateLinkFactory extends Factory
{
    protected $model = AffiliateLink::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $platform = fake()->randomElement(['shopee', 'lazada', 'tiki', 'tiktok']);
        $original = fake()->numberBetween(50, 2000) * 1000;
        $discounted = (int) ($original * fake()->randomFloat(2, 0.5, 0.95));

        return [
            'user_id' => User::factory(),
            'original_url' => 'https://'.$platform.'.vn/'.fake()->slug().'-i.'.fake()->numberBetween(10000, 99999).'.'.fake()->numberBetween(10000, 99999),
            'short_url' => 'https://s.'.$platform.'.vn/'.fake()->bothify('??##??'),
            'platform' => $platform,
            'product_name' => ucfirst(fake()->words(fake()->numberBetween(2, 5), true)),
            'product_image' => null,
            'original_price' => $original,
            'discounted_price' => $discounted,
            'discount_percent' => (int) round((1 - $discounted / $original) * 100),
            'sold_count' => fake()->numberBetween(0, 5000),
            'rating' => fake()->randomFloat(1, 3.5, 5),
        ];
    }
}
