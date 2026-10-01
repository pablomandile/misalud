<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Qué se hizo con una receta.
 *
 * ## ⚠️ "Vencida" no está acá, y es a propósito
 *
 * El plan la listaba como un tercer caso. Se deriva de
 * `fecha_recepcion + vigencia_dias` (`Receta::estaVencida()`), por el mismo
 * motivo por el que la edad no se guarda (regla 4): un estado guardado necesita
 * que algo lo escriba, y eso sería un segundo comando del scheduler cuyo único
 * trabajo es corregir una cuenta que se puede hacer sola. Peor: entre el momento
 * en que vence y el momento en que ese comando corre, la pantalla mostraría como
 * "disponible" una receta que ya no sirve.
 *
 * Lo que queda acá es lo que **una persona decidió** y nadie puede deducir: que
 * la usó.
 */
enum EstadoReceta: string
{
    /** Importada y sin usar. Es el estado con el que nace toda receta. */
    case Disponible = 'disponible';

    /**
     * Ya se presentó en la farmacia.
     *
     * Lo escribe la bandeja del paso 12.3 —la importación nunca produce este
     * estado—, junto con la fecha de uso.
     */
    case Usada = 'usada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::Usada => 'Usada',
        };
    }
}
