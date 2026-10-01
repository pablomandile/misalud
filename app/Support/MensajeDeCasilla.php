<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Un mail de la casilla, ya traducido a algo que no sabe nada de IMAP.
 *
 * ## Para qué existe este DTO
 *
 * Es lo que permite que **todas las reglas del paso se puedan testear sin
 * red**: la ventana con solapamiento, el filtro por remitente, la deduplicación,
 * qué adjunto entra y qué no. `LectorDeCasilla` es la única pieza que habla con
 * el servidor y lo único que hace es producir estos objetos;
 * `SincronizadorDeRecetas` -donde viven las decisiones- no distingue si salieron
 * de Gmail o de un array escrito a mano en un test.
 *
 * Sin esta separación, probar "una receta que llegó el mismo día dos veces no se
 * importa dos veces" necesitaría una casilla de verdad con un mail puesto a
 * mano, y nadie la escribiría.
 */
final readonly class MensajeDeCasilla
{
    /**
     * @param  string  $identificador  el `Message-ID`, o un reemplazo
     *                                 determinístico cuando el mail no trae uno
     *                                 (ver `LectorDeCasilla::identificadorDe()`)
     * @param  list<AdjuntoDeCasilla>  $adjuntos
     */
    public function __construct(
        public string $identificador,
        public string $remitente,
        public ?string $asunto,
        public CarbonImmutable $fecha,
        public array $adjuntos,
    ) {}

    /**
     * Con qué se deduplica un mail: su `Message-ID`, o un reemplazo.
     *
     * ⚠️ **El reemplazo no es un lujo.** Un mail sin `Message-ID` es raro pero
     * existe —remitentes mal configurados—, y sin esto su `message_id_hash`
     * quedaría nulo. En MySQL un UNIQUE admite todos los nulos que quiera, así que
     * ese mail se reimportaría **en cada corrida, para siempre**, escribiendo otra
     * copia cifrada de su adjunto en el disco cada hora. Es el peor tipo de bug:
     * crece solo y nadie lo mira.
     *
     * El reemplazo se arma con fecha, remitente y asunto porque es todo lo que
     * hay, y **es determinístico**: la corrida siguiente calcula el mismo valor y
     * la deduplicación funciona igual que con un `Message-ID` de verdad.
     *
     * Es una función pura y vive acá, al lado del campo que llena, para que se
     * pueda probar sin un servidor IMAP: `LectorDeCasilla` -que es la única pieza
     * sin tests automáticos, por no poder tenerlos- solo la llama.
     */
    public static function identificadorPara(
        ?string $messageId,
        CarbonImmutable $fecha,
        string $remitente,
        ?string $asunto,
    ): string {
        $limpio = $messageId === null ? '' : trim($messageId);

        if ($limpio !== '') {
            return $limpio;
        }

        return 'sin-message-id:'.hash('sha256', implode('|', [
            $fecha->format('c'),
            $remitente,
            $asunto ?? '',
        ]));
    }
}
