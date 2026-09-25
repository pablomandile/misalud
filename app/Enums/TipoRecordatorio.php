<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * De qué avisa un recordatorio.
 *
 * Es la mitad de la clave de idempotencia —`origen_type` + `origen_id` +
 * `tipo`—, y por eso está acá y no como texto libre: un mismo origen puede
 * llegar a tener más de un aviso (uno a 24 horas y otro a una semana, el día
 * que haga falta), y lo que los distingue es esto.
 *
 * ## La anticipación la declara el tipo, en un solo lugar
 *
 * `horasDeAnticipacion()` es lo único que decide cuándo se avisa, y por eso
 * no vive repartido entre los observers. Se resta del instante del evento:
 *
 *     fecha del recordatorio = instante del evento − anticipación
 *
 * ⚠️ **Es aritmética de instantes, sin zona horaria, y es a propósito.** Un
 * "avisar 24 horas antes" no necesita saber en qué huso vive nadie: el
 * resultado es el mismo instante para todos. La alternativa —"el día
 * anterior a las 9 de la mañana, hora del usuario"— no tiene una respuesta
 * única cuando una ficha la comparten varias personas en husos distintos,
 * que es exactamente el caso que el pivote `paciente_usuario` habilita. Es
 * el mismo razonamiento que ya usa `misalud:cerrar-tratamientos-vencidos`.
 *
 * ⚠️ Cuando el origen es una fecha de CALENDARIO y no un instante
 * —`tratamientos.fin` es una columna `date`, que Carbon lee a medianoche
 * UTC—, restarle 24 horas da la medianoche UTC del día anterior, o sea las
 * 21:00 en Argentina. Es una hora razonable para recibir "mañana termina tu
 * tratamiento", pero conviene saber que **salió así por la aritmética y no
 * porque alguien la eligiera**. Afinarla obligaría a saber la zona de cada
 * destinatario, que es justo lo que un proceso de fondo no puede hacer.
 */
enum TipoRecordatorio: string
{
    /** Tenés turno. Sale de `turnos.fecha_hora`, que es un instante. */
    case TurnoProximo = 'turno_proximo';

    /**
     * Se termina un tratamiento. Sale de `tratamientos.fin`, que es una
     * fecha de calendario —ver el aviso de arriba—.
     */
    case TratamientoTermina = 'tratamiento_termina';

    public function etiqueta(): string
    {
        return match ($this) {
            self::TurnoProximo => 'Turno próximo',
            self::TratamientoTermina => 'Tratamiento que termina',
        };
    }

    /**
     * Cuánto antes del evento se avisa.
     *
     * Las dos van a 24 horas: es el aviso que sirve —"mañana tenés que
     * hacer algo"— y el que deja tiempo de reaccionar sin ser tan temprano
     * que uno se lo olvide de nuevo.
     */
    public function horasDeAnticipacion(): int
    {
        return match ($this) {
            self::TurnoProximo => 24,
            self::TratamientoTermina => 24,
        };
    }
}
