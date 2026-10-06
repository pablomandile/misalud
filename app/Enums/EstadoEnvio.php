<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * En qué terminó un envío.
 *
 * Se guarda también el que **falló**: el historial es lo que se consulta cuando
 * la obra social dice "no nos llegó nada", y "lo intenté el martes y no salió"
 * es una respuesta tan útil como "salió el martes".
 */
enum EstadoEnvio: string
{
    case Enviado = 'enviado';
    case Fallido = 'fallido';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Enviado => 'Enviado',
            self::Fallido => 'No salió',
        };
    }
}
