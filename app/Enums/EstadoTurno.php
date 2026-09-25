<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * En qué anda un turno.
 *
 * Tres casos y no cuatro: **no hay "ausente"**. Que alguien no haya ido es
 * información que el sistema no necesita distinguir de un turno cancelado
 * —las dos cosas significan "no pasó"— y separarlas invitaría a que la
 * pantalla opine sobre por qué (regla 1). Mismo criterio que
 * `EstadoOrdenEstudio`.
 *
 * La columna de la base es un `string`, no un ENUM de MySQL: sumar un caso
 * acá no obliga a acordarse de una migración, que es el error que pasa los
 * tests -sqlite no valida ENUM- y revienta en producción al primer guardado.
 */
enum EstadoTurno: string
{
    /** Está agendado y todavía no pasó. */
    case Programado = 'programado';

    /** Ya fue. */
    case Asistido = 'asistido';

    /** Se dio de baja: lo canceló el consultorio, o no se fue. */
    case Cancelado = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Programado => 'Programado',
            self::Asistido => 'Asistido',
            self::Cancelado => 'Cancelado',
        };
    }

    /**
     * ¿Sigue en pie?
     *
     * Es lo que decide dos cosas a la vez, y por eso vive acá y no repetido:
     * qué va en la agenda de lo que viene, y **si corresponde un
     * recordatorio**. Un turno cancelado no avisa nada -ver
     * `TurnoObserver`-.
     */
    public function estaVigente(): bool
    {
        return $this === self::Programado;
    }
}
