<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PedidoProcedimiento;
use App\Models\Tratamiento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PedidoProcedimiento>
 */
class PedidoProcedimientoFactory extends Factory
{
    protected $model = PedidoProcedimiento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tratamiento_id' => Tratamiento::factory(),
            // Lejos de hoy: es fecha de calendario y la valida FechaNoFutura.
            'iniciado_el' => now()->subDays($this->faker->numberBetween(10, 60))->startOfDay(),
            'completado_el' => null,
        ];
    }

    public function completado(): static
    {
        return $this->state(fn (): array => [
            'completado_el' => now()->subDays(2)->startOfDay(),
        ]);
    }
}
