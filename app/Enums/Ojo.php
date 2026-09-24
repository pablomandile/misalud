<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cuál de los dos ojos.
 *
 * Las siglas son las de cualquier receta de óptica —OD y OI—, pero **nunca
 * viajan solas a la pantalla**: `etiqueta()` las acompaña del nombre
 * completo. La mayoría de la gente no sabe qué es OD, y acá una confusión no
 * queda en un dato raro: termina en unos anteojos hechos al revés.
 */
enum Ojo: string
{
    case Derecho = 'od';
    case Izquierdo = 'oi';

    /** Lo que dice el papel: "OD", "OI". */
    public function sigla(): string
    {
        return match ($this) {
            self::Derecho => 'OD',
            self::Izquierdo => 'OI',
        };
    }

    /** Lo que se muestra: la sigla **más** lo que significa. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Derecho => 'OD · ojo derecho',
            self::Izquierdo => 'OI · ojo izquierdo',
        };
    }
}
