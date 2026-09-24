<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Qué tan grave es una alergia, **según lo que cargó la persona**.
 *
 * No lo deduce el sistema de nada: es el dato tal como se lo dijo un médico
 * o como lo vivió quien lo carga (regla 1: el sistema registra, no
 * aconseja). La pantalla lo muestra como texto y no como un semáforo.
 */
enum SeveridadAlergia: string
{
    case Leve = 'leve';

    case Moderada = 'moderada';

    case Grave = 'grave';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Leve => 'Leve',
            self::Moderada => 'Moderada',
            self::Grave => 'Grave',
        };
    }
}
