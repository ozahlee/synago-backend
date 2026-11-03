<?php

namespace Database\Factories;

use App\Models\Ministry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ministry> */
class MinistryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).' Ministry',
            'description' => fake()->sentence(),
            'meeting_day' => fake()->dayOfWeek(),
            'meeting_time' => fake()->time('H:i'),
            'status' => 'active',
        ];
    }
}
