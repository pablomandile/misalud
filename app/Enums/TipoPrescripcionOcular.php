<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Para qué son los anteojos que indica la receta.
 *
 * La columna de la base es un `string`, no un ENUM de MySQL: sumar un caso
 * acá no obliga a acordarse de una migración, que es el error que pasa los
 * tests —sqlite no valida ENUM— y revienta en producción al primer guardado.
 */
enum TipoPrescripcionOcular: string
{
    case Lejos = 'lejos';
    case Cerca = 'cerca';
    case Bifocal = 'bifocal';
    case Multifocal = 'multifocal';
    case Progresiva = 'progresiva';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Lejos => 'Para lejos',
            self::Cerca => 'Para cerca',
            self::Bifocal => 'Bifocal',
            self::Multifocal => 'Multifocal',
            self::Progresiva => 'Progresiva',
        };
    }
}
