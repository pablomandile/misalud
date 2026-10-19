<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoReceta;
use App\Models\Paciente;
use App\Models\RecetaMedica;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecetaMedica>
 */
class RecetaMedicaFactory extends Factory
{
    protected $model = RecetaMedica::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'paciente_id' => Paciente::factory(),
            'medico_id' => null,
            'medicamento_id' => null,
            'detalle' => $this->faker->randomElement([
                'Enalapril 10 mg, 2 cajas',
                'Levotiroxina 50 mcg x 50 comprimidos',
                'Metformina 850 mg, 1 caja',
            ]),
            'fecha' => now()->subDays($this->faker->numberBetween(0, 10))->startOfDay(),
            'vigencia_dias' => 30,
            'estado' => EstadoReceta::Disponible,
            'notas' => null,
        ];
    }

    public function usada(): static
    {
        return $this->state(fn (): array => ['estado' => EstadoReceta::Usada]);
    }
}
