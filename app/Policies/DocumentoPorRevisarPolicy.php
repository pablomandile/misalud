<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DocumentoPorRevisar;
use App\Models\User;

/**
 * Quién ve y resuelve un documento que llegó al buzón y quedó para revisar.
 *
 * Es el mismo criterio que `DocumentoPorRevisar::visiblesPara()`: el mail vino
 * del correo de esta cuenta, o es de una ficha que esta persona puede editar.
 * Ver, resolver y descartar piden lo mismo: no hay un "lector" de un documento
 * que todavía no está en ninguna ficha.
 *
 * Sus adjuntos los autoriza `AdjuntoPolicy` delegando acá, como con cualquier
 * otro dueño de archivos.
 */
class DocumentoPorRevisarPolicy
{
    public function view(User $usuario, DocumentoPorRevisar $documento): bool
    {
        return $this->puede($usuario, $documento);
    }

    public function update(User $usuario, DocumentoPorRevisar $documento): bool
    {
        return $this->puede($usuario, $documento);
    }

    public function delete(User $usuario, DocumentoPorRevisar $documento): bool
    {
        return $this->puede($usuario, $documento);
    }

    private function puede(User $usuario, DocumentoPorRevisar $documento): bool
    {
        if ($documento->usuario_id !== null && $documento->usuario_id === $usuario->id) {
            return true;
        }

        return $documento->paciente?->rolDe($usuario)?->puedeEditar() ?? false;
    }
}
