<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Clave;
use App\Models\Paciente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Clave>
 */
class ClaveFactory extends Factory
{
    protected $model = Clave::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'paciente_id' => Paciente::factory(),
            'sitio' => $this->faker->randomElement(['Portal de la obra social', 'Laboratorio', 'Turnos del hospital']),
            'usuario' => $this->faker->safeEmail(),
            'clave' => $this->faker->password(12, 20),
            'notas' => null,
        ];
    }
}
