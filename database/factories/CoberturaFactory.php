<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TipoCobertura;
use App\Models\Cobertura;
use App\Models\Paciente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cobertura>
 */
class CoberturaFactory extends Factory
{
    protected $model = Cobertura::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'paciente_id' => Paciente::factory(),
            'tipo' => TipoCobertura::ObraSocial,
            'entidad' => $this->faker->company(),
            'plan' => 'Plan '.$this->faker->word(),
            'nro_afiliado' => $this->faker->numerify('##########'),
            'telefono' => $this->faker->phoneNumber(),
            'telefono_urgencias' => $this->faker->phoneNumber(),
            'sitio_web' => null,
            'vigencia_desde' => null,
            'vigencia_hasta' => null,
            'activa' => true,
            'notas' => null,
        ];
    }
}
