<?php

namespace Database\Factories;

use App\Models\HiddenPlanMonth;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HiddenPlanMonth>
 */
class HiddenPlanMonthFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'month' => '2026-01-01',
        ];
    }
}
