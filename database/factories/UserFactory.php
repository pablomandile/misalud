<?php

namespace Database\Factories;

use App\Models\User;
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
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            // Por defecto ya aceptó la versión vigente: si no, el middleware
            // AceptoTerminos frenaría a todo usuario de prueba y la suite entera
            // fallaría. El caso contrario es `sinAceptarTerminos()`.
            'terminos_version' => config('legales.version'),
            'terminos_aceptados_en' => now(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    /**
     * Una cuenta que todavía no aceptó los términos: las de antes de que
     * existieran, o una recién creada con Google.
     */
    public function sinAceptarTerminos(): static
    {
        return $this->state(fn (array $attributes) => [
            'terminos_version' => null,
            'terminos_aceptados_en' => null,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
