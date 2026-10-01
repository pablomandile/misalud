<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CuentaMail;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Una casilla configurada, con credenciales de mentira.
 *
 * ⚠️ **El host es `.test` a propósito, y eso es parte del test.** Un
 * `imap.gmail.com` acá haría que cualquier test que llame sin querer a
 * `ProbadorDeCasilla` salga a internet: lento, dependiente de la red, y
 * apuntando a un servidor real desde la suite. Un `.test` no resuelve en
 * ninguna parte, así que ese descuido falla rápido y de forma obvia en vez de
 * pasar en verde tardando dos segundos.
 *
 * @extends Factory<CuentaMail>
 */
class CuentaMailFactory extends Factory
{
    protected $model = CuentaMail::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'usuario_id' => User::factory(),
            'host' => 'imap.ejemplo.test',
            'puerto' => 993,
            'direccion' => $this->faker->unique()->safeEmail(),
            'password' => 'clave-de-aplicacion-de-mentira',
            'carpeta' => 'INBOX',
            'filtros' => null,
        ];
    }

    /**
     * Con filtros por remitente.
     *
     * @param  list<string>  $remitentes
     */
    public function filtrando(array $remitentes): static
    {
        return $this->state(fn (): array => ['filtros' => $remitentes]);
    }
}
