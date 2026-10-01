<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Un archivo adjunto a un mail, con su contenido ya en memoria.
 *
 * No trae el mime que declaró el mail **a propósito**: lo deduce
 * `ArchivoService` del contenido real con finfo, por la misma razón por la que
 * usa `getMimeType()` y no `getClientMimeType()` en una subida del navegador. El
 * `Content-Type` de un mail lo escribe quien lo manda y no hay ningún motivo
 * para creerle.
 */
final readonly class AdjuntoDeCasilla
{
    public function __construct(
        public string $nombre,
        public string $contenido,
    ) {}
}
