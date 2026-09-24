<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * En qué anda una orden de estudio.
 *
 * Una orden es el **papel que da el médico antes**; el estudio es el
 * resultado de después. Que sean dos cosas es lo que permite la pantalla
 * "pendientes de hacer", que sin tabla propia no existiría: un estudio que
 * todavía no se hizo no es un estudio con campos vacíos, es otra cosa.
 */
enum EstadoOrdenEstudio: string
{
    /** Está el papel, el estudio todavía no se hizo. */
    case Pendiente = 'pendiente';

    /** Ya se lo hizo. Puede tener el estudio cargado o no todavía. */
    case Hecha = 'hecha';

    /** Ya no hace falta: se venció, cambió la indicación, se descartó. */
    case Anulada = 'anulada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Hecha => 'Hecha',
            self::Anulada => 'Anulada',
        };
    }

    /**
     * ¿Es de las que hay que ir a hacerse?
     *
     * Lo usa la pantalla para poner primero lo que está esperando, que es lo
     * único para lo que sirve tener las órdenes cargadas.
     */
    public function estaPendiente(): bool
    {
        return $this === self::Pendiente;
    }
}
