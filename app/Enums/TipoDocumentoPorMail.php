<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Qué puede ser un documento que llega al buzón: lo dice la palabra del
 * asunto, y decide en qué pantalla de la ficha termina.
 */
enum TipoDocumentoPorMail: string
{
    case Receta = 'receta';
    case Orden = 'orden';
    case Estudio = 'estudio';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Receta => 'Receta',
            self::Orden => 'Orden de estudio',
            self::Estudio => 'Estudio',
        };
    }

    /**
     * Con qué tipo se guarda el archivo: el mismo que si se lo subiera desde la
     * pantalla de ese registro.
     */
    public function tipoAdjunto(): TipoAdjunto
    {
        return match ($this) {
            self::Receta => TipoAdjunto::Receta,
            self::Orden => TipoAdjunto::OrdenEstudio,
            self::Estudio => TipoAdjunto::InformeEstudio,
        };
    }
}
