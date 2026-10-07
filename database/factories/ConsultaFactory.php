<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Consulta;
use App\Models\Paciente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consulta>
 */
class ConsultaFactory extends Factory
{
    protected $model = Consulta::class;

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
            'turno_id' => null,
            'fecha_hora' => now()->subDays($this->faker->numberBetween(1, 120))->setTime(10, 0),
            'motivo' => $this->faker->randomElement(['Control', 'Dolor de cabeza', 'Resultados de análisis']),
            'notas' => null,
        ];
    }
}
