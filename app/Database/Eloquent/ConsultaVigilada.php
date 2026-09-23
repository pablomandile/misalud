<?php

declare(strict_types=1);

namespace App\Database\Eloquent;

use App\Contracts\CifraDatos;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Builder que se niega a filtrar u ordenar por una columna cifrada.
 *
 * Sin esto, `Medico::where('nombre', 'Pérez')` compila, corre y devuelve
 * **cero filas**, siempre. No lanza ningún error: el ciphertext guardado nunca
 * es igual al texto buscado, porque el IV es aleatorio. El síntoma es "no
 * encuentra nada", que se lee como "no hay datos" y no como "esta consulta es
 * imposible". Lo mismo un `orderBy`, que ordena por bytes de ciphertext: da un
 * orden estable, arbitrario y sin relación con el contenido.
 *
 * Un grep en los tests no alcanza para atrapar esto —`where('nombre', ...)` es
 * legítimo en las tablas que no cifran ese campo—, así que la verificación vive
 * acá, donde sabe contra qué modelo se está consultando.
 *
 * La salida válida es `dondeIndiceCiego()`, que compara por la columna de hash.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class ConsultaVigilada extends Builder
{
    /**
     * @param  \Closure|string|array<mixed>|Expression  $column
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and'): static
    {
        if (is_string($column)) {
            $this->rechazarSiEsCifrada($column, 'filtrar por');
        }

        parent::where($column, $operator, $value, $boolean);

        return $this;
    }

    /**
     * @param  \Closure|Expression|string  $column
     * @param  'asc'|'desc'|\SortDirection  $direction
     */
    public function orderBy($column, $direction = 'asc'): static
    {
        if (is_string($column)) {
            $this->rechazarSiEsCifrada($column, 'ordenar por');
        }

        parent::orderBy($column, $direction);

        return $this;
    }

    private function rechazarSiEsCifrada(string $columna, string $accion): void
    {
        $modelo = $this->getModel();

        if (! $modelo instanceof CifraDatos) {
            return;
        }

        // Puede venir calificada ("medicos.nombre") o con alias de tabla.
        $sinTabla = str_contains($columna, '.')
            ? substr($columna, (int) strrpos($columna, '.') + 1)
            : $columna;

        if (! in_array($sinTabla, $modelo->camposCifrados(), true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'No se puede %s [%s] en %s: es una columna cifrada y el ciphertext cambia en cada '
            .'guardado, así que la consulta devolvería cero filas sin dar error. '
            .'Usá dondeIndiceCiego() con su columna de hash, o traé las filas y filtrá en PHP.',
            $accion,
            $sinTabla,
            $modelo::class,
        ));
    }
}
