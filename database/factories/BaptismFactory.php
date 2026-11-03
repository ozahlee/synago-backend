<?php

namespace Database\Factories;

use App\Models\Baptism;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Baptism> */
class BaptismFactory extends Factory
{
    public function definition(): array
    {
        $gender = fake()->randomElement(['male', 'female']);

        return [
            'kind' => 'baptism',
            'status' => 'completed',
            'name' => fake()->name($gender),
            'gender' => $gender,
            'date_of_birth' => fake()->dateTimeBetween('-40 years', '-1 month'),
            'date' => fake()->dateTimeBetween('-1 year', 'now'),
            'mode' => 'sprinkling',
            'officiant' => 'Rev. '.fake()->lastName(),
            'place' => 'St. John\'s Church',
            'father_name' => fake()->name('male'),
            'mother_name' => fake()->name('female'),
            'sponsors' => fake()->name().', '.fake()->name(),
            'class_completed' => true,
        ];
    }

    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => 'scheduled',
            'date' => fake()->dateTimeBetween('+1 week', '+3 months'),
            'class_completed' => fake()->boolean(),
        ]);
    }
}
