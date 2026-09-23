<?php

declare(strict_types=1);

namespace App\Rules;

use App\Contracts\CifraDatos;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Unicidad sobre una columna cifrada, por su índice ciego.
 *
 * `Rule::unique('coberturas', 'entidad')` no detecta nada: compara contra el
 * ciphertext, que cambia en cada guardado por el IV aleatorio (ver
 * `ConsultaVigilada`). Sin esta regla, dos coberturas iguales pasan la
 * validación y el UNIQUE de la base las frena con un 500 en vez de un error
 * de campo prolijo.
 *
 * Uso:
 *
 *     'entidad' => ['required', new IndiceCiegoUnico(
 *         Cobertura::class, 'entidad', ['paciente_id' => $paciente->id], $cobertura?->id,
 *     )],
 */
readonly class IndiceCiegoUnico implements ValidationRule
{
    /**
     * @param  class-string<Model&CifraDatos>  $modelo
     * @param  array<string, mixed>  $ambito  columnas EN CLARO por las que además acotar
     *                                        (típicamente `paciente_id` o `usuario_id`:
     *                                        la unicidad casi siempre es "para esta
     *                                        persona", no global)
     * @param  int|null  $ignorarId  el propio registro, al editar
     */
    public function __construct(
        private string $modelo,
        private string $campo,
        private array $ambito = [],
        private ?int $ignorarId = null,
    ) {}

    public function validate(string $atributo, mixed $valor, Closure $fallar): void
    {
        if (! is_string($valor) || $valor === '') {
            return;
        }

        /*
         * Se arma la comparación a mano, sin pasar por el scope
         * `dondeIndiceCiego()`: acá $modelo es un class-string genérico
         * (`Model&CifraDatos`), y PHPStan/Larastan no logra resolver un scope
         * definido en un trait contra un tipo tan abierto. `indicesCiegos()` y
         * `hashCiego()` sí están en la interfaz `CifraDatos`, así que quedan
         * tipados de punta a punta.
         */
        $columnaHash = (new $this->modelo)->indicesCiegos()[$this->campo]
            ?? throw new RuntimeException(
                "{$this->modelo} no declara un índice ciego para [{$this->campo}].",
            );

        $consulta = $this->modelo::query();

        foreach ($this->ambito as $columna => $valorAmbito) {
            $consulta->where($columna, $valorAmbito);
        }

        $consulta->where($columnaHash, $this->modelo::hashCiego($valor));

        if ($this->ignorarId !== null) {
            $consulta->whereKeyNot($this->ignorarId);
        }

        if ($consulta->exists()) {
            // Mensaje literal y no una clave de traducción: el resto de los
            // FormRequest del proyecto ya usa mensajes en español directo
            // (ver `messages()` en cada uno), no una capa de i18n.
            $fallar('Ya existe un registro con ese :attribute.');
        }
    }
}
