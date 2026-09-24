<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Una potencia de lente, que solo existe **de 0,25 en 0,25**.
 *
 * Las lentes se fabrican en pasos de un cuarto de dioptría: `-1,25` y
 * `-1,50` existen, `-1,30` no. Por eso esto es una validación y no un
 * consejo —de los que el sistema no da, regla 1—: un `-1,30` no es una
 * graduación poco común, es un error de tipeo, y guardarlo sin chistar lo
 * deja listo para que alguien se lo lleve a la óptica.
 *
 * ⚠️ **La cuenta va en enteros, no con `fmod()`.** En binario, `1.30` y
 * `0.75` no son exactos: `fmod(0.75, 0.25)` puede dar `2.7E-17` en vez de
 * `0`, y entonces la regla rechazaría un valor **válido**. Pasar a
 * centésimas con `round()` primero deja la comparación en el terreno donde
 * sí es exacta.
 *
 * El tope de dos decimales no lo pone esta regla sino `decimal:0,2`, que va
 * al lado en el FormRequest: sin él, un `1,249999` redondearía a 125
 * centésimas y pasaría.
 */
readonly class PasoDeDioptria implements ValidationRule
{
    public function validate(string $atributo, mixed $valor, Closure $fallar): void
    {
        if ($valor === null || $valor === '' || ! is_numeric($valor)) {
            // Que no sea un número lo reporta `numeric`, no esta regla.
            return;
        }

        $centesimas = (int) round((float) $valor * 100);

        if ($centesimas % 25 !== 0) {
            $fallar('Las lentes van de 0,25 en 0,25: por ejemplo -1,25 o +2,00.');
        }
    }
}
