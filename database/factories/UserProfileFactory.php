<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserProfile>
 */
class UserProfileFactory extends Factory
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
            // Kept to the same charset the update validation allows
            // (letters, numbers, underscore) so factory-made profiles are
            // always valid inputs too, not just valid database rows.
            'username' => 'user_'.fake()->unique()->numberBetween(100000, 999999),
            'date_of_birth' => fake()->date(),
            'country_code' => fake()->countryCode(),
            'locale' => 'en',
            'timezone' => 'UTC',
        ];
    }
}
