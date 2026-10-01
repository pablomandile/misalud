<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CuentaMail;
use App\Models\User;

/**
 * La casilla es del usuario: la regla entera es "¿es tuya?".
 *
 * ## Por qué no reusa `CatalogoPolicy`
 *
 * Se parece —las dos miran `usuario_id` y ninguna toca el pivote
 * `paciente_usuario`— pero `CatalogoPolicy` tiene media clase dedicada a las
 * **semillas compartidas**: `view()` deja ver lo que tiene `usuario_id` NULL,
 * `update()` lo niega, y `duplicar()` existe como la única salida frente a
 * ellas. Acá no hay semillas y no puede haberlas: una casilla compartida sería
 * la contraseña de alguien en el listado de otro. Colgarse de esa Policy
 * dejaría tres reglas vivas que no significan nada sobre esta tabla, y la
 * primera que alguien lea al agregar un caso va a ser la equivocada.
 */
class CuentaMailPolicy
{
    public function viewAny(User $usuario): bool
    {
        return true;
    }

    public function view(User $usuario, CuentaMail $cuenta): bool
    {
        return $cuenta->usuario_id === $usuario->id;
    }

    public function create(User $usuario): bool
    {
        return true;
    }

    public function update(User $usuario, CuentaMail $cuenta): bool
    {
        return $this->view($usuario, $cuenta);
    }

    public function delete(User $usuario, CuentaMail $cuenta): bool
    {
        return $this->view($usuario, $cuenta);
    }

    /**
     * Probar la conexión no modifica nada nuestro, así que alcanza con poder
     * verla. Igual **usa la contraseña guardada** para abrir una sesión IMAP
     * contra un servidor de verdad, y por eso tiene su propia entrada: el día
     * que haga falta limitarlo —un tope de intentos, por ejemplo— el lugar
     * donde ponerlo ya existe y no hay que decidir si va en `view` o en
     * `update`.
     */
    public function probar(User $usuario, CuentaMail $cuenta): bool
    {
        return $this->view($usuario, $cuenta);
    }

    /**
     * Importar ahora, sin esperar a que corra el scheduler.
     *
     * Entrada propia y no `update`: lo que hace no es editar la casilla sino
     * **crear recetas** y mover su marca de sincronización. Tiene el mismo dueño
     * que todo lo demás, pero el día que haya que limitarlo -que alguien no
     * pueda disparar una importación cada dos segundos- el lugar ya existe.
     */
    public function sincronizar(User $usuario, CuentaMail $cuenta): bool
    {
        return $this->view($usuario, $cuenta);
    }
}
