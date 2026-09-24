<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TipoMedicion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TipoMedicion>
 */
class TipoMedicionFactory extends Factory
{
    protected $model = TipoMedicion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'usuario_id' => User::factory(),
            'nombre' => 'Peso '.$this->faker->unique()->numerify('##'),
            'unidad' => 'kg',
            'unidad_secundaria' => null,
            'etiqueta_principal' => null,
            'etiqueta_secundaria' => null,
            'min_normal' => null,
            'max_normal' => null,
            'min_normal_secundario' => null,
            'max_normal_secundario' => null,
            'decimales' => 1,
        ];
    }

    /**
     * Un tipo de DOS valores, como la presión: el caso que define el
     * esquema y el que hay que probar en todos lados.
     */
    public function deDosValores(): static
    {
        return $this->state(fn (): array => [
            'nombre' => 'Presión arterial '.$this->faker->unique()->numerify('##'),
            'unidad' => 'mmHg',
            'etiqueta_principal' => 'Sistólica',
            'etiqueta_secundaria' => 'Diastólica',
            'min_normal' => 90,
            'max_normal' => 140,
            'min_normal_secundario' => 60,
            'max_normal_secundario' => 90,
            'decimales' => 0,
        ]);
    }

    /**
     * Una semilla compartida: sin dueño, visible para todos, editable por
     * nadie. Acá es el caso normal y no la excepción -ver la migración-.
     */
    public function semilla(): static
    {
        return $this->state(fn (): array => ['usuario_id' => null]);
    }
}
