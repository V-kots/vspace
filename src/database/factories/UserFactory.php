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
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'second_name' => fake()->lastName(),
            'birthdate' => fake()->date(),
            'email' => fake()->unique()->safeEmail(),
            'gender' => fake()->randomElement(['male', 'female', 'other']),
            'biography' => fake()->sentence(),
            'city' => fake()->city(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
        ];
    }
}
