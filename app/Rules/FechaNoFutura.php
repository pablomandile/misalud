<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Una fecha de CALENDARIO que no puede estar en el futuro.
 *
 * Existe como regla propia, y no como tres líneas repetidas en cada
 * FormRequest, porque acá vive la trampa que `CLAUDE.md` marca como la más
 * fácil de cometer con fechas en este proyecto:
 *
 * > `hoy()` es para comparar contra `datetime`; **`hoyCalendario()` contra
 * > `date`**.
 *
 * Lo que manda un `<input type="date">` no tiene hora ni zona: es el día tal
 * como lo ve quien escribe. Compararlo contra `hoy()` —que es un instante,
 * la medianoche de esa persona en UTC— corre la comparación tres horas y, en
 * la franja entre las 21:00 y la medianoche argentina, **rechaza el día de
 * hoy por futuro**. No da ningún síntoma hasta que alguien carga algo de
 * noche.
 *
 * Para un `datetime` la regla es otra —hace falta la zona y un margen para
 * el reloj del dispositivo—: eso vive en `MedicionGuardarRequest`, no acá.
 */
readonly class FechaNoFutura implements ValidationRule
{
    public function __construct(private User $usuario) {}

    public function validate(string $atributo, mixed $valor, Closure $fallar): void
    {
        if (! is_string($valor) || $valor === '') {
            return;
        }

        try {
            // En UTC, que es como Carbon lee una columna `date`.
            $fecha = CarbonImmutable::parse($valor, 'UTC')->startOfDay();
        } catch (\Exception) {
            // Texto que no es una fecha: lo reporta la regla `date`, no esta.
            return;
        }

        if ($fecha->greaterThan($this->usuario->hoyCalendario())) {
            $fallar('La fecha no puede ser futura.');
        }
    }
}
