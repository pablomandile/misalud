<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\TipoAdjunto;
use App\Models\Adjunto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * Lo implementa todo registro del que pueden colgar archivos: el paciente,
 * una cobertura (su credencial), un medicamento del catálogo (su prospecto),
 * una orden de estudio (el papel del médico).
 *
 * La implementación la pone el trait `App\Concerns\TieneAdjuntos`; la
 * interfaz existe para que `AdjuntoController` pueda recibir "algo que tiene
 * archivos" sin enumerar los cuatro tipos a mano ni caer en `Model` pelado
 * -donde `adjuntos()` no existe para el analizador-.
 *
 * Llegó con el CUARTO dueño. Con tres, el mismo cuerpo de subida copiado
 * tres veces todavía se podía leer; con cuatro ya no, y cada copia es un
 * lugar donde olvidarse el `Gate::authorize` o el prefijo del disco.
 *
 * `@phpstan-require-extends` dice que todo esto es un modelo de Eloquent,
 * que es cierto en los cuatro casos: sin eso, el controlador no podría
 * tipar el parámetro como `Model&TieneArchivos`.
 *
 * @phpstan-require-extends Model
 */
interface TieneArchivos
{
    /**
     * @return MorphMany<Adjunto, covariant \Illuminate\Database\Eloquent\Model>
     */
    public function adjuntos(): MorphMany;

    /**
     * Los archivos de un tipo puntual.
     *
     * @return Collection<int, Adjunto>
     */
    public function adjuntosDe(TipoAdjunto $tipo): Collection;

    /**
     * El prefijo bajo el que se guardan sus archivos en el disco privado.
     *
     * Lo declara cada modelo y no lo arma el controlador: es lo que evita
     * que dos dueños distintos terminen escribiendo en la misma carpeta
     * porque alguien copió una línea y no cambió el string.
     */
    public function carpetaDeArchivos(): string;
}
