<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Lo implementa todo modelo que guarde contenido cifrado.
 *
 * La implementación la pone el trait `App\Concerns\CifraCampos`; la interfaz
 * existe para que el resto del código —el comando de recifrado, el builder
 * vigilado, el test de guardia— pueda preguntar por estos métodos sabiendo que
 * están, en vez de averiguarlo con `method_exists`.
 */
interface CifraDatos
{
    /**
     * Los campos que este modelo guarda cifrados, según sus casts.
     *
     * @return list<string>
     */
    public function camposCifrados(): array;

    /**
     * Mapa campo de origen => columna de hash.
     *
     * @return array<string, string>
     */
    public function indicesCiegos(): array;

    /**
     * Recalcula las columnas de hash a partir de su campo de origen.
     */
    public function actualizarIndicesCiegos(): void;

    /**
     * HMAC determinístico del valor normalizado.
     */
    public static function hashCiego(string $valor): string;
}
