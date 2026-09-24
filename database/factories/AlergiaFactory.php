<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SeveridadAlergia;
use App\Models\Alergia;
use App\Models\Paciente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alergia>
 */
class AlergiaFactory extends Factory
{
    protected $model = Alergia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'paciente_id' => Paciente::factory(),
            'sustancia' => $this->faker->randomElement([
                'Penicilina', 'Polen', 'Maní', 'Ibuprofeno', 'Látex',
            ]).' '.$this->faker->unique()->numerify('##'),
            'reaccion' => 'Urticaria',
            'severidad' => SeveridadAlergia::Moderada,
            'notas' => null,
        ];
    }

    public function grave(): static
    {
        return $this->state(fn (): array => ['severidad' => SeveridadAlergia::Grave]);
    }
}
