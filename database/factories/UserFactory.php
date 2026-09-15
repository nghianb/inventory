<?php

namespace Database\Factories;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Nhân viên đã bật 2FA TOTP, kèm recovery code (lưu dạng hash như Filament).
     *
     * @param  array<string>  $recoveryCodes
     */
    public function withTwoFactor(array $recoveryCodes = ['recovery-code']): static
    {
        return $this->state(fn (array $attributes) => [
            'app_authentication_secret' => AppAuthentication::make()->generateSecret(),
            'app_authentication_recovery_codes' => array_map(
                fn (string $code): string => Hash::make($code),
                $recoveryCodes,
            ),
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
