<?php

namespace Database\Factories;

use App\Models\Workshop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workshop>
 */
class WorkshopFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true).'ワークショップ',
            'description' => $this->faker->sentence(),
            'duration_minutes' => $this->faker->randomElement([60, 90, 120]),
            'is_active' => true,
        ];
    }
}
