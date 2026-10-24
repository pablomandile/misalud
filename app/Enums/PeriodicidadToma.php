<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cada cuánto se toma un medicamento, en grueso: lo que se repite todos los
 * días, una vez por semana, algunos días de la semana (cuáles, en
 * `tratamientos.dias_semana`), una vez por mes, una vez por año o una sola vez. El detalle ("cada 8
 * horas", "el primer lunes") va en `frecuencia`, como texto.
 *
 * ⚠️ Es un ENUM de MySQL: un caso nuevo acá obliga a ensanchar la columna, o
 * revienta en producción con un 500 (sqlite no lo valida).
 */
enum PeriodicidadToma: string
{
    case Diaria = 'diaria';
    case Semanal = 'semanal';
    case Personalizada = 'personalizada';
    case Mensual = 'mensual';
    case Anual = 'anual';
    case Unica = 'unica';

    /**
     * Los días de la semana, ISO (1 = lunes): la inicial para el botón y el
     * nombre para leerlo. La X del miércoles es la convención de acá, para no
     * repetir la M del martes.
     */
    public const DIAS = [
        1 => ['inicial' => 'L', 'nombre' => 'lunes'],
        2 => ['inicial' => 'M', 'nombre' => 'martes'],
        3 => ['inicial' => 'X', 'nombre' => 'miércoles'],
        4 => ['inicial' => 'J', 'nombre' => 'jueves'],
        5 => ['inicial' => 'V', 'nombre' => 'viernes'],
        6 => ['inicial' => 'S', 'nombre' => 'sábado'],
        7 => ['inicial' => 'D', 'nombre' => 'domingo'],
    ];

    /**
     * "Lunes, miércoles y viernes": con palabras, no con iniciales, que en una
     * lista se leen mal.
     *
     * @param  list<int>  $dias
     */
    public static function nombrarDias(array $dias): string
    {
        $nombres = array_values(array_map(
            fn (int $dia): string => self::DIAS[$dia]['nombre'],
            array_filter($dias, fn (int $dia): bool => isset(self::DIAS[$dia])),
        ));

        $ultimo = array_pop($nombres);
        $texto = $nombres === [] ? (string) $ultimo : implode(', ', $nombres).' y '.$ultimo;

        return mb_strtoupper(mb_substr($texto, 0, 1)).mb_substr($texto, 1);
    }

    /**
     * @return list<array{valor: int, inicial: string, nombre: string}>
     */
    public static function opcionesDeDias(): array
    {
        return array_map(
            fn (int $dia, array $datos): array => ['valor' => $dia, ...$datos],
            array_keys(self::DIAS),
            self::DIAS,
        );
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Diaria => 'Todos los días',
            self::Semanal => 'Una vez por semana',
            self::Personalizada => 'Algunos días de la semana',
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
