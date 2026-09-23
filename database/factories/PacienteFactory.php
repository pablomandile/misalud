<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Paciente;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Paciente>
 */
class PacienteFactory extends Factory
{
    protected $model = Paciente::class;

    public function definition(): array
    {
        return [
            'usuario_id' => User::factory(),
            'nombre' => fake()->name(),
            'fecha_nacimiento' => fake()->dateTimeBetween('-90 years', '-1 year')->format('Y-m-d'),
            'sexo' => fake()->randomElement(['femenino', 'masculino', 'otro']),
            'grupo_sanguineo' => fake()->randomElement(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']),
            'notas' => null,
        ];
    }
}
