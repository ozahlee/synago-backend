<?php

namespace Database\Factories;

use App\Models\Alert;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Alert> */
class AlertFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'message' => fake()->sentence(),
            'type' => fake()->randomElement(['info', 'success', 'warning', 'error']),
        ];
    }
}
