<?php

declare(strict_types=1);

namespace App\Policies;

use App\Contracts\EsCatalogo;
use App\Models\User;
use App\Support\CatalogoDeLaFicha;
use Illuminate\Database\Eloquent\Model;

/**
 * **Una sola Policy para los cuatro catálogos** (médicos, centros,
 * medicamentos, vacunas) y para los tipos de medición de la Etapa 6.
 *
 * Es la hermana de `RegistroClinicoPolicy`, y existe aparte por una razón
 * concreta: un catálogo **no cuelga de un paciente**, cuelga del usuario. Su
 * autorización mira `usuario_id` y nunca el pivote `paciente_usuario`.
 *
 * Meterlos en la misma Policy que el dominio clínico habría obligado a que
 * `pacienteDelRegistro()` devolviera algo para un médico, que no pertenece a
 * ningún paciente en particular — y el resultado habría sido inventar un
 * paciente cualquiera para poder responder.
 */
class CatalogoPolicy
{
    public function viewAny(User $usuario): bool
    {
        return true;
    }

    /**
     * Ver: lo propio y las semillas compartidas.
     */
    public function view(User $usuario, Model&EsCatalogo $registro): bool
    {
        return $registro->esSemilla()
            || $this->esSuyo($usuario, $registro)
            // Lo que usa una ficha que esta persona ve (el médico del dueño en
            // un estudio de la ficha compartida): ya lo ve en la historia.
            || in_array($registro->getKey(), CatalogoDeLaFicha::idsEnFichasDe($registro::class, $usuario), true);
    }

    public function create(User $usuario): bool
    {
        return true;
    }

    /**
     * Editar: **lo propio, o lo del dueño de una ficha que cuida. Nunca una
     * semilla.**
     *
     * Una semilla la comparten todos los usuarios: dejar que alguien la
     * edite sería dejarlo cambiarle el catálogo a desconocidos. La salida es
     * `duplicar()`, que se la lleva a su propio catálogo y ahí sí la
     * modifica (regla 5 de CLAUDE.md).
     *
     * El cuidador de una ficha corrige la lista del dueño de esa ficha
     * (decisión del usuario): es la misma persona que ya carga y corrige la
     * historia. Un lector no, y un extraño tampoco.
     */
    public function update(User $usuario, Model&EsCatalogo $registro): bool
    {
        if ($registro->esSemilla()) {
            return false;
        }

        return $this->esSuyo($usuario, $registro)
            || in_array($registro->getAttribute('usuario_id'), CatalogoDeLaFicha::dueniosQueCuida($usuario), true);
    }

    /**
     * Borrar: **solo lo propio**. Corregir un teléfono se deshace; sacar algo
     * de la lista de otro no (decisión del usuario).
     */
    public function delete(User $usuario, Model&EsCatalogo $registro): bool
    {
        return ! $registro->esSemilla() && $this->esSuyo($usuario, $registro);
    }

    /**
     * Duplicar a su propio catálogo: hace falta poder VERLO, no editarlo.
     *
     * Es justamente lo que se hace con una semilla, que no se puede editar.
     */
    public function duplicar(User $usuario, Model&EsCatalogo $registro): bool
    {
        return $this->view($usuario, $registro);
    }

    private function esSuyo(User $usuario, Model $registro): bool
    {
        return $registro->getAttribute('usuario_id') === $usuario->id;
    }
}
