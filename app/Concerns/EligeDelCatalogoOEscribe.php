<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Contracts\EsCatalogo;
use App\Support\CatalogoVisible;
use Illuminate\Database\Eloquent\Model;

/**
 * Un campo de catálogo que se elige de un `<select>` o, con la opción
 * «Otro: lo escribo», se escribe a mano. Lo usan el medicamento de un
 * tratamiento y la vacuna de una dosis.
 *
 * Reemplazó a un `<datalist>`: esa lista de sugerencias aparece solo al
 * empezar a escribir, y en el celular casi no se ve, así que nadie veía las
 * opciones comunes ya cargadas ("solo se puede escribir", lo reportó el
 * usuario).
 *
 * Esto corre en `prepareForValidation()`, antes de que las reglas miren:
 *
 * - Eligió de la lista: vale el id, y el nombre (un campo oculto que igual
 *   viaja) se descarta.
 * - Eligió «Otro»: se busca el nombre escrito entre lo que esta persona puede
 *   elegir —propio primero, después lo común, después lo del dueño de la ficha
 *   o lo que la ficha ya usa— y, si coincide, se convierte en ese id. Si no
 *   coincide con nada, queda sin id y el controlador lo crea en el catálogo
 *   propio.
 */
trait EligeDelCatalogoOEscribe
{
    /**
     * El valor de la opción «Otro: lo escribo». Repetido en `ElegirDelCatalogo.vue`.
     */
    public const OTRO = 'otro';

    /**
     * @param  class-string<Model&EsCatalogo>  $modelo
     * @param  list<int>  $elegiblesAdemas  lo del dueño de la ficha y lo que la ficha ya usa
     */
    protected function resolverEleccionDeCatalogo(string $modelo, string $campoId, string $campoNombre, array $elegiblesAdemas): void
    {
        if ($this->input($campoId) === self::OTRO) {
            $this->merge([$campoId => null]);
        } elseif ($this->filled($campoId)) {
            $this->merge([$campoNombre => null]);

            return;
        }

        $nombre = $this->input($campoNombre);

        if (! is_string($nombre) || trim($nombre) === '') {
            $this->merge([$campoNombre => null]);

            return;
        }

        $nombre = trim($nombre);
        $this->merge([$campoNombre => $nombre]);

        $usuarioId = $this->user()?->getAuthIdentifier();
        $muestra = new $modelo;
        $columnaHash = $muestra->indicesCiegos()[$muestra->columnaNombre()];

        // Por el índice ciego: el nombre está cifrado y no se puede comparar en SQL.
        $candidatos = $modelo::query()
            ->where($columnaHash, $modelo::hashCiego($nombre))
            ->where(fn ($ambito) => $ambito
                ->where(CatalogoVisible::para($usuarioId))
                ->orWhereIn('id', $elegiblesAdemas)
            )
            ->get(['id', 'usuario_id']);

        $elegido = $candidatos->firstWhere('usuario_id', $usuarioId)
            ?? $candidatos->firstWhere('usuario_id', null)
            ?? $candidatos->first();

        if ($elegido !== null) {
            $this->merge([$campoId => $elegido->getKey()]);
        }
    }
}
