<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\MotivoDeRevision;
use App\Enums\ResultadoDeMail;
use App\Enums\TipoDocumentoPorMail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * La respuesta del buzón a quien mandó un documento: si se guardó y dónde, si
 * quedó para revisar y por qué, o que no traía archivos.
 *
 * - **Solo a remitentes reconocidos**, nunca a una dirección desconocida:
 *   contestarle confirmaría que el buzón existe y le respondería a correo
 *   basura. Lo decide `ProcesadorDeBuzon`.
 * - **Sin `ShouldQueue`**: corre en el scheduler, que ya es segundo plano, y un
 *   worker en hosting compartido se cae en silencio (ver los avisos).
 * - **El asunto no lleva nombres** ni la palabra Receta/Orden/Estudio: es lo
 *   que se ve en la pantalla bloqueada, y si alguien contesta este mail, la
 *   respuesta no tiene que parecer un documento nuevo.
 * - Texto plano, con `{!! !!}` (ver `aviso-recordatorio.blade.php`).
 */
class RespuestaDelBuzon extends Mailable
{
    public function __construct(
        public readonly ResultadoDeMail $resultado,
        public readonly ?TipoDocumentoPorMail $tipo = null,
        public readonly ?string $paciente = null,
        public readonly ?MotivoDeRevision $motivo = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'MiSalud: recibimos tu mail');
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.respuesta-buzon',
            with: [
                'guardado' => $this->resultado === ResultadoDeMail::Guardado,
                'porRevisar' => $this->resultado === ResultadoDeMail::PorRevisar,
                // Otros nombres que las propiedades: Laravel pasa las públicas a
                // la vista, y con el mismo nombre la vista recibiría el enum.
                'queEs' => $this->tipo?->etiqueta(),
                'ficha' => $this->paciente,
                'porque' => $this->motivo?->explicacion(),
                'enlace' => route('casilla.index'),
            ],
        );
    }
}
