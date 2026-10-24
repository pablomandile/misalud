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

    /**
     * Toca la próxima dosis de una vacuna. Sale de
     * `aplicaciones_vacuna.proxima_dosis`, otra fecha de calendario.
     */
    case VacunaProxima = 'vacuna_proxima';

    /**
     * Se acerca la aplicación de un medicamento de "una vez por año". Sale de
     * `tratamientos.proxima_aplicacion`, fecha de calendario. Son DOS avisos
     * del mismo origen —un mes antes y una semana antes— y por eso dos tipos:
     * la clave de idempotencia es origen + tipo (pedido del usuario).
     */
    case AplicacionAnualMes = 'aplicacion_anual_mes';

    case AplicacionAnualSemana = 'aplicacion_anual_semana';

    public function etiqueta(): string
    {
        return match ($this) {
            self::TurnoProximo => 'Turno próximo',
            self::TratamientoTermina => 'Tratamiento que termina',
            self::VacunaProxima => 'Próxima dosis de vacuna',
            self::AplicacionAnualMes, self::AplicacionAnualSemana => 'Próxima aplicación de un medicamento',
        };
    }

    /**
     * El asunto del mail.
     *
     * ⚠️ **Sin el nombre de nadie, a propósito.** El asunto es la parte más
     * expuesta de un mail: aparece en la pantalla bloqueada del teléfono, en
     * la vista previa de la bandeja, y es lo que más termina en los registros
     * de cualquier servidor de correo por el que pase. El nombre del paciente
     * va en el cuerpo; acá solo qué clase de aviso es.
     */
    public function asunto(): string
    {
        return match ($this) {
            self::TurnoProximo => 'MiSalud: tenés un turno próximo',
            self::TratamientoTermina => 'MiSalud: un tratamiento está por terminar',
            self::VacunaProxima => 'MiSalud: se acerca la próxima dosis de una vacuna',
            self::AplicacionAnualMes => 'MiSalud: en un mes toca la aplicación de un medicamento',
            self::AplicacionAnualSemana => 'MiSalud: en una semana toca la aplicación de un medicamento',
        };
    }

    /**
     * Si el evento es una fecha de CALENDARIO (un día) y no un instante.
     *
     * ⚠️ Decide cómo se MUESTRA la fecha del evento, y equivocarse corre un día
     * entero. Un `date` se guarda a medianoche UTC: pasado a la zona de
     * Argentina es **las 21:00 del día anterior**. Así, el mail de un
     * tratamiento que terminaba el 15 decía "el 14 a las 21:00" —un día antes y
     * con una hora que nadie cargó—. Es la trampa de `hoy()` contra
     * `hoyCalendario()`, del lado de lo que se muestra.
     */
    public function esDeCalendario(): bool
    {
        return match ($this) {
            self::TurnoProximo => false,
            self::TratamientoTermina,
            self::VacunaProxima,
            self::AplicacionAnualMes,
            self::AplicacionAnualSemana => true,
        };
    }

    /**
     * Cuánto antes del evento se avisa.
     *
     * Turnos, tratamientos y vacunas van a 24 horas: es el aviso que sirve
     * —"mañana tenés que hacer algo"— y el que deja tiempo de reaccionar sin
     * ser tan temprano que uno se lo olvide de nuevo.
     *
     * La aplicación anual avisa con más tiempo, porque suele haber que pedir
     * turno o conseguir el medicamento: a 30 días ("un mes antes") y a 7. Son
     * días corridos, no meses de calendario: la anticipación es aritmética de
     * instantes, igual que las demás.
     */
    public function horasDeAnticipacion(): int
    {
        return match ($this) {
            self::TurnoProximo => 24,
            self::TratamientoTermina => 24,
            self::VacunaProxima => 24,
            self::AplicacionAnualMes => 30 * 24,
            self::AplicacionAnualSemana => 7 * 24,
        };
    }
}
