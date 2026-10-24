<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enfermedad;
use App\Models\RegistroEnfermedad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistroEnfermedad>
 */
class RegistroEnfermedadFactory extends Factory
{
    protected $model = RegistroEnfermedad::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enfermedad_id' => Enfermedad::factory(),
            // Desde 1 y no desde 0: es una fecha de calendario y la validan con
            // FechaNoFutura. Con 0, de noche en Argentina la fecha UTC ya es
            // mañana para la cuenta (ver FechasDeLasFactoriesTest).
            'fecha' => now()->subDays($this->faker->numberBetween(1, 90)),
            'nota' => $this->faker->sentence(),
        ];
    }
}
