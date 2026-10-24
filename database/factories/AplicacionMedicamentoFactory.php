<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PeriodicidadToma;
use App\Models\AplicacionMedicamento;
use App\Models\Paciente;
use App\Models\Tratamiento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AplicacionMedicamento>
 */
class AplicacionMedicamentoFactory extends Factory
{
    protected $model = AplicacionMedicamento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'paciente_id' => Paciente::factory(),
            // El medicamento, de la MISMA ficha: como en la pantalla.
            'tratamiento_id' => fn (array $atributos) => Tratamiento::factory()->create([
                'paciente_id' => $atributos['paciente_id'],
                'periodicidad' => PeriodicidadToma::Mensual,
            ])->id,
            'enfermedad_id' => null,
            'fecha' => now()->subDays($this->faker->numberBetween(1, 365))->startOfDay(),
            'notas' => null,
        ];
    }
}
