<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\TipoAdjunto;
use App\Models\Adjunto;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * Lo usa todo modelo que pueda tener archivos colgados.
 *
 * Es un trait y no una relación escrita en cada modelo porque son ocho o nueve
 * los que la necesitan y la definición es idéntica en todos: una copia que se
 * escriba distinta -apuntando a otro nombre de relación, por ejemplo- rompería
 * la resolución del paciente y con ella la autorización.
 */
trait TieneAdjuntos
{
    /**
     * @return MorphMany<Adjunto, $this>
     */
    public function adjuntos(): MorphMany
    {
        return $this->morphMany(Adjunto::class, 'adjuntable');
    }

    /**
     * Los archivos de un tipo puntual: el frente de la credencial, el informe
     * de un estudio.
     *
     * Se filtra en PHP sobre la relación ya cargada y no con otro `where` a la
     * base: los adjuntos de un registro son unos pocos, y así una pantalla que
     * ya hizo eager loading no dispara una consulta por cada tipo que muestra.
     *
     * @return Collection<int, Adjunto>
     */
    public function adjuntosDe(TipoAdjunto $tipo): Collection
    {
        return $this->adjuntos->where('tipo', $tipo)->values();
    }
}
