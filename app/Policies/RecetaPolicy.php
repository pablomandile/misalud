<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Receta;
use App\Models\User;

/**
 * Una receta la ve quien administra la casilla a la que llegó el mail.
 *
 * ## Por qué no es `RegistroClinicoPolicy`
 *
 * Esa Policy decide sobre lo que pertenece a un **paciente**, y una receta
 * importada todavía no pertenece a ninguno: un mail de la farmacia no dice de
 * quién es. `pacienteDelRegistro()` tendría que devolver `null`, y para esa
 * Policy un paciente nulo es "no" —correctamente—, así que ninguna receta
 * importada se podría ver.
 *
 * La regla verdadera es la de la casilla: **el mail llegó a tu correo.** Es la
 * misma forma que `CuentaMailPolicy`, mirando `usuario_id`.
 *
 * ⚠️ El día que una receta se pueda asignar a un paciente hay que contestar si
 * un cuidador de esa ficha puede verla. Hoy la respuesta es que no: la ve quien
 * tiene la casilla. No es una omisión, es el alcance de lo que existe.
 */
class RecetaPolicy
{
    public function viewAny(User $usuario): bool
    {
        return true;
    }

    public function view(User $usuario, Receta $receta): bool
    {
        return $receta->usuario_id === $usuario->id;
    }

    /**
     * Nadie crea una receta a mano: **entran solo por importación.**
     *
     * Está declarado en `false` y no simplemente ausente para que se lea como
     * una decisión y no como un olvido. Es la misma idea que hace que
     * `RecordatorioController` tenga un solo método: la ausencia de un camino de
     * escritura es lo que garantiza que la tabla no entre en un estado que
     * ningún mail justifique.
     */
    public function create(User $usuario): bool
    {
        return false;
    }

    public function update(User $usuario, Receta $receta): bool
    {
        return $this->view($usuario, $receta);
    }

    public function delete(User $usuario, Receta $receta): bool
    {
        return $this->view($usuario, $receta);
    }
}
