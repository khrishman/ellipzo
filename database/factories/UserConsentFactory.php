<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserConsent>
 */
class UserConsentFactory extends Factory
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
            'document' => 'terms',
            'version' => '2026-07-24',
            'accepted_at' => now('UTC'),
            'method' => 'registration_checkbox',
        ];
    }
}
