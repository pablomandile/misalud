<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EtapaProcedimiento;
use App\Models\Tratamiento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EtapaProcedimiento>
 */
class EtapaProcedimientoFactory extends Factory
{
    protected $model = EtapaProcedimiento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tratamiento_id' => Tratamiento::factory(),
            'orden' => 0,
            'nombre' => $this->faker->randomElement(['Pedir la receta', 'Autorizar en la obra social', 'Retirar en la farmacia']),
        ];
    }
}
