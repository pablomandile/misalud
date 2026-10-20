<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cada cuánto se toma un medicamento, en grueso: lo que se repite todos los
 * días, una vez por mes, una vez por año o una sola vez. El detalle ("cada 8
 * horas", "el primer lunes") va en `frecuencia`, como texto.
 *
 * ⚠️ Es un ENUM de MySQL: un caso nuevo acá obliga a ensanchar la columna, o
 * revienta en producción con un 500 (sqlite no lo valida).
 */
enum PeriodicidadToma: string
{
    case Diaria = 'diaria';
    case Mensual = 'mensual';
    case Anual = 'anual';
    case Unica = 'unica';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Diaria => 'Todos los días',
            self::Mensual => 'Una vez por mes',
            self::Anual => 'Una vez por año',
            self::Unica => 'Una sola vez',
        };
    }

    /**
     * @return list<array{valor: string, etiqueta: string}>
     */
    public static function opciones(): array
    {
        return array_map(
            fn (self $caso): array => ['valor' => $caso->value, 'etiqueta' => $caso->etiqueta()],
            self::cases(),
        );
    }
}
