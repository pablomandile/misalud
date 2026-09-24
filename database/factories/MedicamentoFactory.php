<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Medicamento;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Medicamento>
 */
class MedicamentoFactory extends Factory
{
    protected $model = Medicamento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'usuario_id' => User::factory(),
            'nombre_comercial' => ucfirst($this->faker->unique()->word()),
            'droga' => $this->faker->randomElement([
                'Ibuprofeno', 'Paracetamol', 'Amoxicilina', 'Losartán',
            ]),
            'para_que_sirve' => $this->faker->sentence(),
            'notas' => null,
        ];
    }

    /**
     * Una semilla compartida: sin dueño, visible para todos, editable por
     * nadie.
     */
    public function semilla(): static
    {
        return $this->state(fn (): array => ['usuario_id' => null]);
    }
}
