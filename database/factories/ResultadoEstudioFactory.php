<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Estudio;
use App\Models\ResultadoEstudio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResultadoEstudio>
 */
class ResultadoEstudioFactory extends Factory
{
    protected $model = ResultadoEstudio::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'estudio_id' => Estudio::factory(),
            'parametro' => 'Glucemia',
            // String, como devuelve una columna cifrada: la factory guarda
            // lo mismo que guarda la app.
            'valor' => (string) $this->faker->numberBetween(70, 110),
            'unidad' => 'mg/dl',
            'rango_referencia' => '70 a 110',
        ];
    }
}
