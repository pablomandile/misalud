<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Documentación que una persona le manda a su farmacia, obra social, médico u
 * óptica.
 *
 * ## ⚠️ SIN `ShouldQueue`, y acá el motivo es más grave que en los avisos
 *
 * En `AvisoDeRecordatorio` el motivo era no depender de un worker que en hosting
 * compartido se cae en silencio. Acá además hay uno de seguridad: **un mailable
 * encolado se serializa entero en la tabla `jobs`**, y este lleva los archivos
 * **ya descifrados**. Encolarlo dejaría las recetas y los estudios de alguien en
 * claro en una tabla de la base, que es justo lo que todo el cifrado del proyecto
 * existe para evitar. Por eso el envío es síncrono, con la pantalla esperando.
 *
 * ## `From` es la app; `Reply-To` es la persona
 *
 * No se puede mandar *desde* el mail de la persona: el servidor de su dominio no
 * nos autoriza (SPF y DMARC), y el mensaje caería en spam o rebotaría. Así que sale
 * de la dirección de la app, pero con `Reply-To` apuntando a quien lo manda: si la
 * farmacia contesta, le contesta a ella y no a una casilla que nadie lee.
 *
 * ## Qué dice y qué no
 *
 * Al revés que los avisos —que salen solos y por eso dicen "cuándo" y no
 * "qué"—, este lo arma una persona eligiendo a quién y qué mandar: el contenido
 * clínico **es** el propósito. Lo único que el sistema agrega es quién lo manda,
 * para que el destinatario sepa de parte de quién viene.
 */
class EnvioDeDocumentos extends Mailable
{
    /**
     * @param  list<array{nombre: string, mime: string, contenido: string}>  $archivos
     */
    public function __construct(
        private readonly User $remitente,
        private readonly string $asunto,
        private readonly ?string $mensaje,
        private readonly array $archivos,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->asunto,
            replyTo: [new Address($this->remitente->email, $this->remitente->name)],
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.envio-documentos',
            with: [
                'remitente' => $this->remitente->name,
                'mensaje' => $this->mensaje,
                'archivos' => array_column($this->archivos, 'nombre'),
            ],
        );
    }

    /**
     * Los archivos, desde el contenido ya descifrado en memoria.
     *
     * El mime es el que se dedujo del contenido al guardar el archivo, no uno que
     * viaje del formulario.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            static fn (array $archivo): Attachment => Attachment::fromData(
                static fn (): string => $archivo['contenido'],
                $archivo['nombre'],
            )->withMime($archivo['mime']),
            $this->archivos,
        );
    }
}
