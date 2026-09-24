<?php

declare(strict_types=1);

namespace App\Concerns;

/**
 * Pasa los números escritos con coma decimal a la forma que entiende PHP.
 *
 * ⚠️ **Es una corrección obligatoria, no una comodidad.** Acá se escribe
 * "72,5", y un teclado numérico de celular en español ofrece la coma. Con
 * eso, sin normalizar, pasa una de dos y las dos son malas:
 *
 * - la regla `numeric` rechaza "72,5" y la persona ve "el valor debe ser un
 *   número" mirando un número perfectamente válido para ella;
 * - o el valor llega igual a un `(float)` y PHP devuelve **72.0**, sin
 *   error y sin aviso: el peso pierde los gramos y nadie se entera.
 *
 * Va en `prepareForValidation()`, **antes** de que la regla `numeric` mire el
 * valor —en el controlador ya sería tarde—.
 *
 * Un "1.234,5" queda como "1.234.5" y lo rechaza `numeric`, que es lo
 * correcto: mejor un error visible que adivinar si el punto era de miles o
 * de decimales. Ninguna variable de esta app llega a los miles.
 */
trait NormalizaDecimales
{
    /**
     * @param  list<string>  $campos
     */
    protected function normalizarDecimales(array $campos): void
    {
        $normalizados = [];

        foreach ($campos as $campo) {
            $valor = $this->input($campo);

            if (is_string($valor)) {
                $normalizados[$campo] = str_replace(',', '.', trim($valor));
            }
        }

        if ($normalizados !== []) {
            $this->merge($normalizados);
        }
    }
}
