<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoEnfermedad;
use App\Models\Enfermedad;
use App\Models\Paciente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enfermedad>
 */
class EnfermedadFactory extends Factory
{
    protected $model = Enfermedad::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'paciente_id' => Paciente::factory(),
            'medico_id' => null,
            'nombre' => $this->faker->randomElement([
                'Hipertensión', 'Diabetes tipo 2', 'Neumonía', 'Gastritis',
            ]).' '.$this->faker->unique()->numerify('##'),
            'fecha_diagnostico' => now()->subMonths($this->faker->numberBetween(1, 60)),
            'estado' => EstadoEnfermedad::Activa,
            'notas' => null,
        ];
    }

    public function cronica(): static
    {
        return $this->state(fn (): array => ['estado' => EstadoEnfermedad::Cronica]);
    }

    public function resuelta(): static
    {
        return $this->state(fn (): array => ['estado' => EstadoEnfermedad::Resuelta]);
    }
}
