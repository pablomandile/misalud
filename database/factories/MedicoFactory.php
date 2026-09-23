<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Medico;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Medico>
 */
class MedicoFactory extends Factory
{
    protected $model = Medico::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'usuario_id' => User::factory(),
            'nombre' => 'Dr. '.$this->faker->lastName(),
            'especialidad' => $this->faker->randomElement([
                'Clínica médica', 'Cardiología', 'Traumatología', 'Oftalmología',
            ]),
            'telefono' => $this->faker->phoneNumber(),
            'email' => null,
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
