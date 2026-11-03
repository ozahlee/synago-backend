<?php

namespace Database\Factories;

use App\Models\Budget;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Budget> */
class BudgetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category' => fake()->unique()->word(),
            'allocated' => fake()->randomFloat(2, 1000, 20000),
            'period' => now()->format('Y-m'),
        ];
    }
}
