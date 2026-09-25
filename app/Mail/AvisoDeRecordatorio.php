<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Recordatorio;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * El aviso de un recordatorio, para UN destinatario.
 *
 * ## ⚠️ SIN `ShouldQueue`, a propósito
 *
 * Lo dispara `misalud:enviar-recordatorios`, que ya corre en segundo plano
 * por el scheduler: encolarlo no lo haría más asíncrono, solo agregaría un
 * `queue:work` del que depender. Y en hosting compartido un worker se cae en
 * silencio, así que el modo de falla sería **quedarse sin avisos sin que
 * nadie se entere** —el peor posible para lo único que esta app hace por su
 * cuenta—.
 *
 * ## Uno por destinatario, nunca uno con varios `To`
 *
 * Dos razones que apuntan al mismo lado:
 *
 * - **la hora**: cada persona lo lee en su propia zona (`users.zona_horaria`),
 *   y un solo mail solo puede traer una;
 * - **la privacidad**: varios `To` o `Cc` le muestran a cada uno la dirección
 *   de los demás, que no es información de nadie más.
 *
 * ## ⚠️ Qué dice y qué NO dice
 *
 * Un mail sale del sistema **sin cifrar** y queda en el servidor de correo de
 * quien lo reciba, fuera de todo lo que esta app controla. Así que dice
 * **cuándo**, no **qué**:
 *
 * | Va en el mail                  | Se queda detrás del login          |
 * | ------------------------------ | ---------------------------------- |
 * | De qué clase es el aviso       | El motivo del turno                |
 * | El nombre del paciente         | Qué médico, en qué centro          |
 * | La fecha y la hora del evento  | Qué medicamento, qué dosis         |
 *
 * El nombre del paciente sí va, y es la única concesión: sin él, un cuidador
 * que administra tres fichas recibe un aviso que no puede usar. Todo lo demás
 * es el contenido clínico de verdad, y para eso está la app.
 *
 * ## Texto plano y no HTML
 *
 * Un aviso de tres líneas no necesita maquetado, se ve bien en cualquier
 * cliente y no depende de que se carguen estilos ni imágenes. Y de paso evita
 * el layout de markdown de Laravel, que viene en inglés y habría que
 * traducir entero para cumplir la regla de idioma del proyecto.
 */
class AvisoDeRecordatorio extends Mailable
{
    public function __construct(
        public readonly Recordatorio $recordatorio,
        public readonly User $destinatario,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->recordatorio->tipo->asunto());
    }

    public function content(): Content
    {
        /*
         * La hora del EVENTO —no la del aviso— y en la zona de **este**
         * destinatario. Es lo que obliga a un mail por persona.
         */
        $cuando = $this->destinatario->enSuZona($this->recordatorio->instanteDelEvento());

        return new Content(
            text: 'mail.aviso-recordatorio',
            with: [
                'saludo' => $this->destinatario->name,
                'aviso' => $this->recordatorio->tipo->etiqueta(),
                'paciente' => $this->recordatorio->paciente?->nombre,
                'cuando' => $cuando?->format('d/m/Y \a \l\a\s H:i'),
                'enlace' => route('dashboard'),
            ],
        );
    }
}
