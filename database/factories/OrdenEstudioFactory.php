<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoOrdenEstudio;
use App\Models\OrdenEstudio;
use App\Models\Paciente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrdenEstudio>
 */
class OrdenEstudioFactory extends Factory
{
    protected $model = OrdenEstudio::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'paciente_id' => Paciente::factory(),
            'medico_id' => null,
            'estudio_solicitado' => $this->faker->randomElement([
                'Análisis de sangre completo',
                'Radiografía de tórax',
                'Ecografía abdominal',
                'Electrocardiograma',
            ]).' '.$this->faker->unique()->numerify('##'),
            'fecha' => now()->subDays($this->faker->numberBetween(0, 90)),
            'estado' => EstadoOrdenEstudio::Pendiente,
            'notas' => null,
        ];
    }

    public function hecha(): static
    {
        return $this->state(fn (): array => ['estado' => EstadoOrdenEstudio::Hecha]);
    }

    public function anulada(): static
    {
        return $this->state(fn (): array => ['estado' => EstadoOrdenEstudio::Anulada]);
    }
}
