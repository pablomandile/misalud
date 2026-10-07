<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\RolPaciente;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * La invitación a ver (o ayudar a llevar) la ficha de una persona.
 *
 * - **El asunto no lleva el nombre del paciente**, por la misma regla que los
 *   avisos: es lo que aparece en la pantalla bloqueada y en cualquier registro de
 *   un servidor de correo. El nombre va en el cuerpo, que es para quien abre el
 *   mail.
 * - **Texto plano**, como los avisos: tres líneas no piden maquetado, y el layout
 *   de markdown de Laravel viene en inglés.
 * - **Sin `ShouldQueue`**: lo dispara la persona y espera la confirmación, y en
 *   hosting compartido un worker se cae en silencio.
 * - El mismo mail tenga o no cuenta el destinatario: así el formulario no sirve
 *   para averiguar quién está registrado en MiSalud.
 */
class InvitacionAFicha extends Mailable
{
    public function __construct(
        private readonly User $quienInvita,
        private readonly Paciente $paciente,
        private readonly RolPaciente $rol,
        private readonly string $url,
        private readonly string $vence,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->quienInvita->name} te invitó a compartir una ficha en MiSalud",
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.invitacion-ficha',
            with: [
                'invita' => $this->quienInvita->name,
                'paciente' => $this->paciente->nombre,
                // El permiso, dicho en el mail y no recién al aceptar.
                'puedeEditar' => $this->rol->puedeEditar(),
                'enlace' => $this->url,
                'vence' => $this->vence,
            ],
        );
    }
}
