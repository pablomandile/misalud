<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AplicacionVacuna;
use App\Models\Paciente;
use App\Models\Vacuna;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AplicacionVacuna>
 */
class AplicacionVacunaFactory extends Factory
{
    protected $model = AplicacionVacuna::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'paciente_id' => Paciente::factory(),
            'vacuna_id' => Vacuna::factory(),
            'centro_id' => null,
            'fecha' => now()->subDays($this->faker->numberBetween(1, 365))->startOfDay(),
            'proxima_dosis' => null,
            'dosis' => '1ª dosis',
            'lote' => null,
            'notas' => null,
        ];
    }
}
