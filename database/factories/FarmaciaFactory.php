<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Farmacia;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Farmacia>
 */
class FarmaciaFactory extends Factory
{
    protected $model = Farmacia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'usuario_id' => User::factory(),
            'nombre' => 'Farmacia '.$this->faker->unique()->lastName(),
            'direccion' => $this->faker->streetAddress(),
            'telefono' => $this->faker->phoneNumber(),
            'whatsapp' => null,
            'email' => null,
            'web' => null,
            'horarios' => null,
            'notas' => null,
        ];
    }
}
