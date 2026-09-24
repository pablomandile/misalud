<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Vacuna;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vacuna>
 */
class VacunaFactory extends Factory
{
    protected $model = Vacuna::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'usuario_id' => User::factory(),
            'nombre' => $this->faker->randomElement([
                'Antigripal', 'Hepatitis B', 'Triple viral', 'Fiebre amarilla', 'COVID-19',
            ]).' '.$this->faker->unique()->numerify('##'),
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
