<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Adjunto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Borra los adjuntos cuyo dueño ya no existe: el archivo del disco y la fila.
 *
 * `adjuntos` es polimórfica y por eso no tiene FK: cuando la base borra un
 * registro en cascada (una ficha se lleva sus estudios, un estudio sus
 * resultados, una cuenta sus recetas), las filas de sus adjuntos quedan
 * apuntando a nada y el archivo sigue en el disco —cifrado, o en claro si es
 * una grabación—, invisible y para siempre.
 *
 * En vez de seguirle la pista a cada cascada, que es frágil (una FK nueva
 * alcanza para que se escape una), esto barre después: todo adjunto cuyo
 * `adjuntable_id` no está en la tabla de su dueño es huérfano. Un dueño en la
 * papelera SÍ existe —su fila sigue ahí—, así que sus archivos no se tocan.
 *
 * Lo corren el borrado de una cuenta y el vaciado diario de la papelera.
 */
final readonly class BarredorDeArchivosHuerfanos
{
    public function __construct(private ArchivoService $archivos) {}

    /**
     * @return int cuántos adjuntos huérfanos se borraron
     */
    public function barrer(): int
    {
        $borrados = 0;

        $tipos = Adjunto::withTrashed()->distinct()->pluck('adjuntable_type');

        foreach ($tipos as $tipo) {
            $clase = Relation::getMorphedModel($tipo) ?? $tipo;

            if (! is_string($clase) || ! is_subclass_of($clase, Model::class)) {
                // Un tipo que ya no es un modelo: no hay forma de saber de quién
                // era. Se avisa (solo el tipo, ningún dato) en vez de adivinar.
                Log::warning('Adjuntos con un tipo de dueño desconocido', ['tipo' => $tipo]);

                continue;
            }

            $modelo = new $clase;
            $tabla = $modelo->getTable();
            $clave = $modelo->getKeyName();

            $huerfanos = Adjunto::withTrashed()
                ->where('adjuntable_type', $tipo)
                ->whereNotExists(fn (Builder $q) => $q
                    ->selectRaw('1')
                    ->from($tabla)
                    ->whereColumn("{$tabla}.{$clave}", 'adjuntos.adjuntable_id'))
                ->get();

            foreach ($huerfanos as $adjunto) {
                // Primero el disco, después la fila: al revés, si algo falla en
                // el medio, queda un archivo que nada referencia.
                $this->archivos->borrar($adjunto);
                $adjunto->forceDelete();
                $borrados++;
            }
        }

        return $borrados;
    }
}
