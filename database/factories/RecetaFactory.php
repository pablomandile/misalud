<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoReceta;
use App\Models\Receta;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Una receta ya importada.
 *
 * Por defecto **disponible y reciente**, que es el caso normal: una receta que
 * llegó hace poco y todavía no se usó. Las vencidas se piden con su estado.
 *
 * @extends Factory<Receta>
 */
class RecetaFactory extends Factory
{
    protected $model = Receta::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'usuario_id' => User::factory(),
            'cuenta_mail_id' => null,
            // Único por fila: de él cuelga el UNIQUE que evita reimportar.
            'message_id' => '<'.$this->faker->unique()->uuid().'@ejemplo.test>',
            'remitente' => 'recetas@farmacia.com.ar',
            'asunto' => 'Tu receta de '.$this->faker->monthName(),
            'fecha_recepcion' => now()->subDays($this->faker->numberBetween(0, 5)),
            'vigencia_dias' => 30,
            'estado' => EstadoReceta::Disponible,
        ];
    }

    /**
     * Llegó hace más días que su vigencia, así que `estaVencida()` da `true`.
     *
     * Se mueve la FECHA y no un estado, porque "vencida" no es un estado
     * guardado: se deriva (ver `EstadoReceta`).
     */
    public function vencida(): static
    {
        return $this->state(fn (): array => [
            'fecha_recepcion' => now()->subDays(45),
            'vigencia_dias' => 30,
        ]);
    }

    public function usada(): static
    {
        return $this->state(fn (): array => ['estado' => EstadoReceta::Usada]);
    }
}
