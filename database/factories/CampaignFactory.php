<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Campaign> */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->catchPhrase(),
            'description' => fake()->sentence(),
            'target' => fake()->randomFloat(2, 5000, 100000),
            'deadline' => fake()->dateTimeBetween('now', '+1 year'),
            'status' => 'active',
        ];
    }
}
