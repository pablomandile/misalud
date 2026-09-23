<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de cobertura médica.
 *
 * `string` en la base, no un ENUM de MySQL: ver el comentario de la migración
 * de `coberturas` y el de `TipoAdjunto`, que es la misma razón repetida.
 */
enum TipoCobertura: string
{
    case ObraSocial = 'obra_social';
    case Prepaga = 'prepaga';
    case Mutual = 'mutual';
    case Pami = 'pami';

    public function etiqueta(): string
    {
        return match ($this) {
            self::ObraSocial => 'Obra social',
            self::Prepaga => 'Prepaga',
            self::Mutual => 'Mutual',
            self::Pami => 'PAMI',
        };
    }
}
