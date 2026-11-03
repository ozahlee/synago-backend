<?php

namespace Database\Factories;

use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Member> */
class MemberFactory extends Factory
{
    public function definition(): array
    {
        $gender = fake()->randomElement(['male', 'female']);

        return [
            'name' => fake()->name($gender),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'gender' => $gender,
            'date_of_birth' => fake()->dateTimeBetween('-70 years', '-5 years'),
            'date_joined' => fake()->dateTimeBetween('-5 years', 'now'),
            'status' => fake()->randomElement(['active', 'active', 'active', 'inactive']),
            'sunday_school' => fake()->boolean(30),
            'address' => fake()->streetAddress(),
            'village' => fake()->randomElement(['Kireka', 'Bweyogerere', 'Namugongo', 'Kyaliwajjala']),
            'parish' => fake()->randomElement(['Kireka', 'Kiwatule', 'Nsasa']),
            'sub_county' => fake()->randomElement(['Kira', 'Nakawa', 'Wakiso']),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => 'inactive']);
    }
}
