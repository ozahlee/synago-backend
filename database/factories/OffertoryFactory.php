<?php

namespace Database\Factories;

use App\Models\Offertory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Offertory> */
class OffertoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'date' => fake()->dateTimeBetween('-6 months', 'now'),
            'category' => fake()->randomElement(Offertory::CATEGORIES),
            'amount' => fake()->randomFloat(2, 10, 5000),
            'payment_method' => fake()->randomElement(['Cash', 'Mobile Money', 'Bank Transfer', 'Cheque']),
            'contributor_name' => fake()->name(),
        ];
    }
}
