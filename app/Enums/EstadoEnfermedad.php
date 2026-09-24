<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * En qué anda una enfermedad.
 *
 * Tres casos y no dos, porque "crónica" es algo que la gente dice y que no
 * es ni activa-que-va-a-terminar ni resuelta: una diabetes no se cura ni se
 * está esperando que se cure. Separarla deja que la pantalla muestre
 * primero lo que sigue vigente sin que una hipertensión de hace diez años
 * aparezca como un cuadro reciente.
 */
enum EstadoEnfermedad: string
{
    /** En curso, y se espera que termine. */
    case Activa = 'activa';

    /** En curso indefinidamente: no se espera que termine. */
    case Cronica = 'cronica';

    /** Ya pasó. */
    case Resuelta = 'resuelta';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Activa => 'Activa',
            self::Cronica => 'Crónica',
            self::Resuelta => 'Resuelta',
        };
    }

    /**
     * ¿Sigue siendo parte del presente de esta persona?
     *
     * Lo usa la pantalla para separar las dos listas. No es un juicio
     * clínico: es lo que la persona misma marcó.
     */
    public function estaVigente(): bool
    {
        return $this !== self::Resuelta;
    }
}
