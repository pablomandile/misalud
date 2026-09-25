<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * El ciclo de vida de un recordatorio.
 *
 * Son cuatro casos porque hay **dos historias distintas** conviviendo, y
 * conviene tenerlas separadas en la cabeza:
 *
 * - lo que hizo el SISTEMA: `Pendiente` → `Enviado`, o `Pendiente` → `Vencido`
 *   si nadie pudo avisar en tiempo;
 * - lo que hizo la PERSONA: cualquiera de esos → `Completado`.
 *
 * Un `Enviado` sigue estando pendiente **para la persona** -ya le llegó el
 * mail, pero no lo resolvió-, así que la pantalla muestra los dos juntos. Lo
 * que `Enviado` evita es mandar el mismo aviso dos veces.
 */
enum EstadoRecordatorio: string
{
    /** Todavía no llegó su hora, o llegó y el comando no corrió aún. */
    case Pendiente = 'pendiente';

    /** Ya se mandó el aviso. Sigue sin resolver, pero no se avisa de nuevo. */
    case Enviado = 'enviado';

    /** La persona lo marcó como hecho. */
    case Completado = 'completado';

    /**
     * Pasó su ventana sin que nadie pudiera avisar.
     *
     * Es el caso del server caído una semana: mandar "tenés turno mañana"
     * por un turno que ya pasó es peor que no mandar nada.
     */
    case Vencido = 'vencido';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Enviado => 'Avisado',
            self::Completado => 'Listo',
            self::Vencido => 'Sin avisar',
        };
    }

    /** ¿Le queda algo por hacer a la persona? Es lo que va en pantalla. */
    public function sigueAbierto(): bool
    {
        return $this === self::Pendiente || $this === self::Enviado;
    }
}
