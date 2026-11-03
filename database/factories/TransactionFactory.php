<?php

namespace Database\Factories;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        $type = fake()->randomElement(['income', 'expense']);

        return [
            'date' => fake()->dateTimeBetween('-6 months', 'now'),
            'type' => $type,
            'category' => $type === 'income'
                ? fake()->randomElement(['Offerings', 'Tithes', 'Donations'])
                : fake()->randomElement(['Utilities', 'Salaries', 'Maintenance', 'Outreach']),
            'amount' => fake()->randomFloat(2, 50, 10000),
            'description' => fake()->sentence(4),
            'payment_method' => fake()->randomElement(['Cash', 'Mobile Money', 'Bank Transfer', 'Cheque']),
        ];
    }

    public function expense(string $category): static
    {
        return $this->state(['type' => 'expense', 'category' => $category]);
    }
}
