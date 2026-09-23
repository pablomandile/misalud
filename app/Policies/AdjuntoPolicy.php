<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Adjunto;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * La autorización de un archivo **se delega en lo que lo contiene**.
 *
 * La regla entera es una línea: *podés hacerle algo a un adjunto si podés
 * hacerle lo mismo a la cosa de la que cuelga*. Un adjunto no tiene dueño
 * propio ni reglas propias — es una foto pegada a otra cosa.
 *
 * Antes esto lo resolvía `RegistroClinicoPolicy` a través de
 * `pacienteDelRegistro()`, que subía por `adjuntable` hasta encontrar un
 * paciente. Funcionaba mientras TODO colgara de un paciente, y se rompía con
 * el primer adjunto que no: el prospecto de un medicamento cuelga de un
 * catálogo, que es **del usuario**, y esa cadena devolvía `null` — es decir,
 * lo negaba siempre, sin que se notara hasta llegar a la Etapa 5.
 *
 * Delegando, cada dueño contesta con SU propia Policy y esto no se vuelve a
 * tocar nunca más:
 *
 * | El adjunto cuelga de… | Contesta…                              |
 * | --------------------- | -------------------------------------- |
 * | `Paciente`            | `PacientePolicy` (rol en el pivote)    |
 * | `Cobertura`           | `RegistroClinicoPolicy` (vía paciente) |
 * | un catálogo           | `CatalogoPolicy` (por `usuario_id`)    |
 *
 * Y de yapa sale gratis una regla correcta: a una **semilla compartida** no
 * se le puede colgar un archivo, porque `CatalogoPolicy::update()` la niega.
 * Hay que duplicarla primero, que es exactamente lo que corresponde.
 */
class AdjuntoPolicy
{
    public function view(User $usuario, Adjunto $adjunto): bool
    {
        return $this->puedeSobreElDuenio($usuario, $adjunto, 'view');
    }

    /**
     * Editar o borrar un archivo **es editar el registro que lo contiene**.
     *
     * Por eso las dos preguntan por `update` del dueño y no por su `delete`:
     * sacarle una foto a una cobertura no es dar de baja la cobertura.
     */
    public function update(User $usuario, Adjunto $adjunto): bool
    {
        return $this->puedeSobreElDuenio($usuario, $adjunto, 'update');
    }

    public function delete(User $usuario, Adjunto $adjunto): bool
    {
        return $this->puedeSobreElDuenio($usuario, $adjunto, 'update');
    }

    private function puedeSobreElDuenio(User $usuario, Adjunto $adjunto, string $accion): bool
    {
        $duenio = $adjunto->adjuntable;

        /*
         * Sin dueño no hay a quién preguntarle, y eso es "no". Acá caen los
         * adjuntos huérfanos —uno cuyo registro se borró con un `forceDelete`,
         * por ejemplo—. La alternativa, tratar el `null` como "no hay nada que
         * proteger", dejaría un archivo clínico abierto a cualquiera.
         */
        if ($duenio === null) {
            return false;
        }

        /*
         * `Gate::forUser($usuario)` y NO `Gate::allows(...)` a secas.
         *
         * La fachada sin `forUser` evalúa contra el usuario AUTENTICADO, que
         * no tiene por qué ser el `$usuario` que recibió esta Policy: cualquier
         * chequeo hecho en nombre de otra persona —un test, un comando, una
         * verificación de permisos ajenos— respondería por el equivocado. Y el
         * modo de falla es silencioso: devuelve `true` cuando el de la sesión
         * sí puede.
         */
        return Gate::forUser($usuario)->allows($accion, $duenio);
    }
}
