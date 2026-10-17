<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state: a customer, the least privileged account type.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_type' => 'customer',
            'is_active' => true,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password_hash' => static::$password ??= Hash::make('password'),
        ];
    }

    /**
     * Indicate that the user is a staff member (admin panel).
     */
    public function staff(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_type' => 'staff',
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
