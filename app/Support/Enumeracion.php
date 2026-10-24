<?php

declare(strict_types=1);

namespace App\Support;

/**
 * "a, b y c": una lista dicha como se la diría en voz alta.
 */
final class Enumeracion
{
    /**
     * @param  list<string>  $partes
     */
    public static function unir(array $partes): string
    {
        $ultimo = array_pop($partes);

        return $partes === [] ? (string) $ultimo : implode(', ', $partes).' y '.$ultimo;
    }
}
