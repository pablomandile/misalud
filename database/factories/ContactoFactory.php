<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TipoContacto;
use App\Models\Contacto;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contacto>
 */
class ContactoFactory extends Factory
{
    protected $model = Contacto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'usuario_id' => User::factory(),
            'nombre' => 'Farmacia '.$this->faker->lastName(),
            // `.test`: ningún mail de un test puede terminar en una casilla real.
            'email' => $this->faker->unique()->userName().'@farmacia.test',
            'tipo' => TipoContacto::Farmacia,
        ];
    }
}
