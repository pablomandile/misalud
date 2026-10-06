<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A quién se le manda documentación.
 *
 * Son los destinos reales de este módulo y nada más: la farmacia (la receta), la
 * obra social (la orden para autorizar), el médico (un estudio) y la óptica (la
 * receta de anteojos). Sirve para agrupar la libreta y para que la persona
 * reconozca a quién está por mandarle algo.
 */
enum TipoContacto: string
{
    case Farmacia = 'farmacia';
    case ObraSocial = 'obra_social';
    case Medico = 'medico';
    case Optica = 'optica';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Farmacia => 'Farmacia',
            self::ObraSocial => 'Obra social o prepaga',
            self::Medico => 'Médico',
            self::Optica => 'Óptica',
            self::Otro => 'Otro',
        };
    }
}
