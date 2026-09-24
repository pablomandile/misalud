<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * "Lo que esta persona puede ver de un catálogo": lo suyo más las semillas
 * compartidas.
 *
 * Existe como pieza propia porque es la **tercera** vez que hace falta la
 * misma condición —el listado de un catálogo, la validación de qué médicos
 * puede vincular un centro, y ahora qué tipo de medición puede elegir una
 * medición—, y porque escribirla mal no da error:
 *
 *     $consulta->where('usuario_id', $id)->orWhereNull('usuario_id')
 *
 * Así, suelta, el `orWhereNull` se mezcla con cualquier otro `where` que se
 * encadene después y **las semillas de todos se cuelan en el resultado**.
 * Devuelve de más, en silencio. Agrupada en una clausura que entra en un
 * solo `where()`, no hay forma de encadenarla mal.
 *
 * Sirve igual para un builder de Eloquent y para el de consultas crudo (el
 * que recibe `Rule::exists()->where()`): solo usa `where` y `orWhereNull`,
 * que están en los dos.
 */
final class CatalogoVisible
{
    /**
     * @return Closure(Builder): void
     */
    public static function para(int|string|null $usuarioId): Closure
    {
        return function (Builder $consulta) use ($usuarioId): void {
            $consulta->where('usuario_id', $usuarioId)->orWhereNull('usuario_id');
        };
    }
}
