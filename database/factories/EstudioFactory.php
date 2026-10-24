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
            // Desde 1 y no desde 0: es una fecha de calendario y la validan con
            // FechaNoFutura. Con 0, de noche en Argentina la fecha UTC ya es
            // mañana para la cuenta (ver FechasDeLasFactoriesTest).
            'fecha' => now()->subDays($this->faker->numberBetween(1, 180)),
            'notas' => null,
        ];
    }
}
