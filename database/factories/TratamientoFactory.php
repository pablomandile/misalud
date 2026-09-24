<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Medicamento;
use App\Models\Paciente;
use App\Models\Tratamiento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tratamiento>
 */
class TratamientoFactory extends Factory
{
    protected $model = Tratamiento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'paciente_id' => Paciente::factory(),
            'medicamento_id' => Medicamento::factory(),
            'medico_id' => null,
            'enfermedad_id' => null,
            'dosis' => '500mg',
            'frecuencia' => 'Cada 8 horas',
            'inicio' => now()->subDays($this->faker->numberBetween(1, 60)),
            'fin' => null,
            'activo' => true,
            'notas' => null,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => [
            'activo' => false,
            'fin' => now()->subDays($this->faker->numberBetween(1, 30)),
        ]);
    }

    public function vencido(): static
    {
        return $this->state(fn (): array => [
            'activo' => true,
            'inicio' => now()->subDays(30),
            'fin' => now()->subDays($this->faker->numberBetween(1, 10)),
        ]);
    }
}
