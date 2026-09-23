<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TipoCentro;
use App\Models\Centro;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Centro>
 */
class CentroFactory extends Factory
{
    protected $model = Centro::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'usuario_id' => User::factory(),
            'nombre' => 'Centro '.$this->faker->lastName(),
            'tipo' => TipoCentro::Consultorio,
            'direccion' => $this->faker->streetAddress(),
            'telefono' => $this->faker->phoneNumber(),
            'notas' => null,
        ];
    }

    /**
     * Una semilla compartida: sin dueño, visible para todos, editable por
     * nadie.
     */
    public function semilla(): static
    {
        return $this->state(fn (): array => ['usuario_id' => null]);
    }
}
