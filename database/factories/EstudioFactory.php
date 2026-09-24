<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Estudio;
use App\Models\Paciente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Estudio>
 */
class EstudioFactory extends Factory
{
    protected $model = Estudio::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'paciente_id' => Paciente::factory(),
            'medico_id' => null,
            'centro_id' => null,
            'enfermedad_id' => null,
            'tipo' => $this->faker->randomElement([
                'Análisis de sangre completo',
                'Radiografía de tórax',
                'Ecografía abdominal',
                'Electrocardiograma',
            ]),
            'fecha' => now()->subDays($this->faker->numberBetween(0, 180)),
            'notas' => null,
        ];
    }
}
