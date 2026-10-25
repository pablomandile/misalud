<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * El pedido de medicamentos que una persona le manda a su médico, a un centro o
 * a una farmacia. Como un envío de documentación: lo arma ella, eligiendo a quién
 * y qué, así que el contenido va en el mail; `From` la app y `Reply-To` la
 * persona, para que le contesten a ella.
 *
 * Sin `ShouldQueue`, igual que el envío: se manda mientras la pantalla espera.
 */
class PedidoDeMedicamentos extends Mailable
{
    /** Sin nombres: es lo que se ve en la pantalla bloqueada de quien lo recibe. */
    public const ASUNTO = 'Pedido de medicamentos';

    /**
     * Un renglón por medicamento, cada uno con su mensaje.
     *
     * @param  list<array{medicamento: string, mensaje: string}>  $renglones
     */
    public function __construct(
        private readonly User $remitente,
        private readonly array $renglones,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: self::ASUNTO,
            replyTo: [new Address($this->remitente->email, $this->remitente->name)],
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.pedido-medicamentos',
            with: [
                'remitente' => $this->remitente->name,
                'renglones' => $this->renglones,
            ],
        );
    }
}
