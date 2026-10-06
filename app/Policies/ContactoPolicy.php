<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Contacto;
use App\Models\User;

/**
 * La libreta es del usuario: la regla entera es "¿es tuyo?".
 *
 * Es la cuarta Policy con esta misma forma (casilla, receta, contacto, envío). Se
 * dejó repetida a propósito: la comparación es una sola línea, y cada una tiene
 * algo propio —la casilla tiene `probar` y `sincronizar`, la receta niega
 * `create`— que una Policy compartida tendría que resolver con casos especiales.
 * Si aparece una quinta sin nada propio, ese es el momento de juntarlas.
 */
class ContactoPolicy
{
    public function viewAny(User $usuario): bool
    {
        return true;
    }

    public function view(User $usuario, Contacto $contacto): bool
    {
        return $contacto->usuario_id === $usuario->id;
    }

    public function create(User $usuario): bool
    {
        return true;
    }

    public function update(User $usuario, Contacto $contacto): bool
    {
        return $this->view($usuario, $contacto);
    }

    public function delete(User $usuario, Contacto $contacto): bool
    {
        return $this->view($usuario, $contacto);
    }
}
