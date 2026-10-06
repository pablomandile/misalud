<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoEnvio;
use App\Mail\EnvioDeDocumentos;
use App\Models\Adjunto;
use App\Models\Contacto;
use App\Models\Envio;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Manda documentos a un contacto y deja registro de lo que pasó.
 *
 * **No autoriza nada**: recibe archivos que `EnvioGuardarRequest` ya autorizó
 * uno por uno contra su dueño. Es el mismo reparto que el resto del proyecto
 * —el FormRequest decide si se puede, el servicio hace—.
 */
class EnviadorDeDocumentos
{
    /**
     * Cuánto pueden sumar los archivos de un envío.
     *
     * ⚠️ **15 MB y no los 25 que acepta Gmail, a propósito.** Un adjunto viaja en
     * base64, que pesa un 37% más que el archivo: 15 MB de PDFs son ~20,5 MB de
     * mail, y con el cuerpo y las cabeceras todavía entran debajo de los 25 MB que
     * cortan Gmail y Outlook. Un tope de 25 MB "porque es lo que dice Gmail" dejaría
     * pasar envíos que el servidor de destino rebota, y el rebote llega horas
     * después a una casilla que nadie mira.
     *
     * Y todo se descifra en memoria antes de mandar, así que este número también es
     * el que cuida el `memory_limit` del hosting.
     */
    public const MAXIMO_BYTES = 15 * 1024 * 1024;

    public function __construct(
        private readonly ArchivoService $archivos,
    ) {}

    /**
     * @param  Collection<int, Adjunto>  $adjuntos
     */
    public function enviar(
        User $usuario,
        Contacto $contacto,
        Collection $adjuntos,
        string $asunto,
        ?string $mensaje,
    ): Envio {
        try {
            $archivos = array_values($adjuntos
                ->map(fn (Adjunto $adjunto): array => [
                    'nombre' => $adjunto->nombre_original,
                    'mime' => $adjunto->mime,
                    'contenido' => $this->archivos->contenido($adjunto),
                ])
                ->all());

            Mail::to($contacto->email, $contacto->nombre)
                ->send(new EnvioDeDocumentos($usuario, $asunto, $mensaje, $archivos));

            $estado = EstadoEnvio::Enviado;
        } catch (Throwable $e) {
            /*
             * Al log van ids y la clase de la excepción, nada más. El mensaje de un
             * error de SMTP suele traer la dirección del destinatario -el mail de un
             * tercero-, y un log es un archivo de texto que termina en cualquier
             * lado. Misma regla que los avisos y la casilla.
             */
            Log::error('No se pudo mandar un envío de documentación', [
                'usuario_id' => $usuario->id,
                'contacto_id' => $contacto->id,
                'archivos' => $adjuntos->pluck('id')->all(),
                'excepcion' => $e::class,
            ]);

            $estado = EstadoEnvio::Fallido;
        }

        /*
         * Se registra DESPUÉS de intentar, con el resultado ya sabido: una fila
         * "pendiente" escrita antes y actualizada después podría quedar para
         * siempre en "pendiente" si el proceso se cae en el medio, y un historial
         * que miente es peor que uno que no tiene esa fila.
         */
        return DB::transaction(function () use ($usuario, $contacto, $adjuntos, $asunto, $mensaje, $estado): Envio {
            $envio = $usuario->envios()->create([
                'contacto_id' => $contacto->id,
                // Fotos del momento: si mañana se corrige el contacto, el historial
                // tiene que seguir diciendo a dónde salió de verdad.
                'destinatario' => $contacto->email,
                'destinatario_nombre' => $contacto->nombre,
                'asunto' => $asunto,
                'cuerpo' => $mensaje,
                'estado' => $estado,
            ]);

            foreach ($adjuntos as $adjunto) {
                $envio->archivos()->create([
                    'adjunto_id' => $adjunto->id,
                    'nombre' => $adjunto->nombre_original,
                    'tamanio_bytes' => $adjunto->tamanio_bytes,
                ]);
            }

            return $envio;
        });
    }
}
