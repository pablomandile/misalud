<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Envio;
use App\Models\User;

/**
 * Un envío lo ve quien lo mandó, y nadie lo edita ni lo borra.
 *
 * `update` y `delete` no existen a propósito, igual que no existen sus rutas: el
 * historial es lo que se consulta cuando la obra social dice "no nos llegó
 * nada", y uno que se puede corregir no prueba nada.
 *
 * Que `create` sea `true` no autoriza nada por sí solo: lo que se manda son
 * archivos, y cada uno se autoriza contra su propio dueño en el momento del
 * envío (ver `EnvioGuardarRequest::authorize()`).
 */
class EnvioPolicy
{
    public function viewAny(User $usuario): bool
    {
        return true;
    }

    public function view(User $usuario, Envio $envio): bool
    {
        return $envio->usuario_id === $usuario->id;
    }

    public function create(User $usuario): bool
    {
        return true;
    }
}
