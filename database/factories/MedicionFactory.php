<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Medicion;
use App\Models\Paciente;
use App\Models\TipoMedicion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Medicion>
 */
class MedicionFactory extends Factory
{
    protected $model = Medicion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'paciente_id' => Paciente::factory(),
            'tipo_medicion_id' => TipoMedicion::factory(),
            'fecha' => now()->subDays($this->faker->numberBetween(0, 60)),
            // String y no float: es lo que devuelve una columna cifrada, así
            // que la factory guarda lo mismo que guarda la app.
            'valor' => (string) $this->faker->numberBetween(60, 90),
            'valor_secundario' => null,
            'notas' => null,
        ];
    }
}
