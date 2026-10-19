<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MotivoDeRevision;
use App\Enums\ResultadoDeMail;
use App\Enums\TipoAdjunto;
use App\Enums\TipoDocumentoPorMail;
use App\Mail\RespuestaDelBuzon;
use App\Models\DocumentoPorRevisar;
use App\Models\MailRecibido;
use App\Models\Paciente;
use App\Models\User;
use App\Support\AdjuntoDeCasilla;
use App\Support\AsuntoDeDocumento;
use App\Support\AutenticidadDelMail;
use App\Support\CabecerasDelMail;
use App\Support\IdentificacionDeRemitente;
use App\Support\MensajeDeCasilla;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Todas las decisiones del buzón: lo procesa `misalud:procesar-buzon`.
 *
 * Recibe DTOs (`MensajeDeCasilla`) y no habla IMAP: el lector es la costura y
 * esto se prueba entero sin red, con un doble del lector.
 *
 * Para cada mail, en este orden:
 *
 * 1. Ya procesado → se saltea (`mails_recibidos`, por el hash del Message-ID).
 * 2. Automático (rebote, vacaciones) → se descarta, sin contestar.
 * 3. Remitente desconocido → se descarta **en silencio**, sin guardar su
 *    dirección.
 * 4. Pasó el tope del día → se descarta, sin contestar.
 * 5. Sin archivos que sirvan → se le contesta que no trajo nada.
 * 6. Si es auténtico, se sabe el tipo y se sabe la ficha → **directo a la
 *    ficha**. Si falta cualquiera de las tres → **por revisar**.
 *
 * ⚠️ Un mail roto no se lleva la tanda: el `try` va por mensaje. Y al log van
 * solo ids y la clase de la excepción, nunca el remitente ni el asunto.
 */
final class ProcesadorDeBuzon
{
    public function __construct(
        private readonly LectorDeCasilla $lector,
        private readonly IdentificadorDePaciente $identificador,
        private readonly RegistradorDeDocumento $registrador,
        private readonly AdjuntadorDeArchivos $adjuntador,
    ) {}

    /**
     * @return array<string, int> cuántos mails terminaron en cada resultado
     */
    public function procesar(bool $seco = false): array
    {
        $mensajes = $this->lector->mensajes((int) config('misalud.buzon.tope_por_corrida'));
        $resumen = [];
        $procesados = [];

        foreach ($mensajes as $mensaje) {
            try {
                $resultado = $this->procesarUno($mensaje, $seco);
            } catch (Throwable $error) {
                Log::error('No se pudo procesar un mail del buzón.', [
                    'uid' => $mensaje->uid,
                    'excepcion' => $error::class,
                ]);

                if (! $seco) {
                    $this->registrarError($mensaje);
                }

                $resumen[ResultadoDeMail::Error->value] = ($resumen[ResultadoDeMail::Error->value] ?? 0) + 1;

                // Con error se queda en el INBOX: alguien lo tiene que mirar.
                continue;
            }

            if ($resultado === null) {
                // Ya estaba procesado: solo falta sacarlo del INBOX.
                $procesados[] = $mensaje->uid;

                continue;
            }

            $resumen[$resultado->value] = ($resumen[$resultado->value] ?? 0) + 1;
            $procesados[] = $mensaje->uid;
        }

        if (! $seco) {
            $this->lector->moverAProcesados(array_values(array_filter($procesados, fn (?int $uid): bool => $uid !== null)));
        }

        return $resumen;
    }

    /**
     * @return ResultadoDeMail|null null si ya se había procesado
     */
    private function procesarUno(MensajeDeCasilla $mensaje, bool $seco): ?ResultadoDeMail
    {
        $hash = MailRecibido::hashCiego($mensaje->identificador);

        if (MailRecibido::query()->where('message_id_hash', $hash)->exists()) {
            return null;
        }

        if (CabecerasDelMail::esAutomatico($mensaje->cabeceras, $mensaje->remitente)) {
            return $this->terminar($mensaje, $hash, ResultadoDeMail::Automatico, null, $seco, guardarRemitente: false);
        }

        $asunto = AsuntoDeDocumento::leer($mensaje->asunto);
        $quien = $this->identificador->identificar($mensaje->remitente, $asunto);

        if ($quien->esDesconocido()) {
            return $this->terminar($mensaje, $hash, ResultadoDeMail::Desconocido, null, $seco, guardarRemitente: false);
        }

        if ($this->excedioElTope($mensaje->remitente)) {
            return $this->terminar($mensaje, $hash, ResultadoDeMail::Excedido, null, $seco);
        }

        $utiles = $this->adjuntador->utiles($mensaje->adjuntos);

        if ($utiles === []) {
            $this->terminar($mensaje, $hash, ResultadoDeMail::SinArchivos, null, $seco);
            $this->responder($mensaje->remitente, new RespuestaDelBuzon(ResultadoDeMail::SinArchivos), $seco);

            return ResultadoDeMail::SinArchivos;
        }

        $autentico = AutenticidadDelMail::verificada(
            CabecerasDelMail::autenticacion($mensaje->cabeceras),
            $mensaje->remitente,
        );

        $motivo = match (true) {
            ! $autentico => MotivoDeRevision::SinVerificar,
            $asunto->tipo === null => MotivoDeRevision::SinTipo,
            $quien->paciente === null => MotivoDeRevision::PacienteAmbiguo,
            default => null,
        };

        // Sin motivo para revisar, el tipo y la ficha se conocen (lo garantiza el match).
        if ($motivo === null) {
            return $this->guardarEnLaFicha($mensaje, $hash, $asunto, $asunto->tipo, $quien, $quien->paciente, $utiles, $seco);
        }

        return $this->dejarParaRevisar($mensaje, $hash, $asunto, $quien, $motivo, $utiles, $seco);
    }

    /**
     * @param  list<AdjuntoDeCasilla>  $utiles
     */
    private function guardarEnLaFicha(
        MensajeDeCasilla $mensaje,
        string $hash,
        AsuntoDeDocumento $asunto,
        TipoDocumentoPorMail $tipo,
        IdentificacionDeRemitente $quien,
        Paciente $paciente,
        array $utiles,
        bool $seco,
    ): ResultadoDeMail {
        if (! $seco) {
            DB::transaction(function () use ($mensaje, $hash, $asunto, $tipo, $quien, $paciente, $utiles): void {
                $registro = $this->registrador->crear(
                    $paciente,
                    $tipo,
                    $asunto->resto($quien->palabrasDelNombre),
                    $this->diaDelMail($mensaje, $quien->cuenta ?? $paciente->usuario),
                );

                $this->adjuntador->adjuntarContenidos($registro, $utiles, $tipo->tipoAdjunto(), $this->descripcion($mensaje, $quien->cuenta ?? $paciente->usuario));

                $this->registrar($mensaje, $hash, ResultadoDeMail::Guardado, $paciente);
            });
        }

        $this->responder($mensaje->remitente, new RespuestaDelBuzon(ResultadoDeMail::Guardado, $tipo, $paciente->nombre), $seco);

        return ResultadoDeMail::Guardado;
    }

    /**
     * Para quién queda: la ficha si se sabe, y la cuenta si vino de una. Si no
     * se sabe la ficha y tampoco vino de una cuenta -el correo de un paciente
     * cargado en dos fichas de dos familias-, queda uno para cada ficha: cada
     * familia resuelve el suyo, y ninguna ve el de la otra.
     *
     * @param  list<AdjuntoDeCasilla>  $utiles
     */
    private function dejarParaRevisar(
        MensajeDeCasilla $mensaje,
        string $hash,
        AsuntoDeDocumento $asunto,
        IdentificacionDeRemitente $quien,
        MotivoDeRevision $motivo,
        array $utiles,
        bool $seco,
    ): ResultadoDeMail {
        /** @var list<array{0: Paciente|null, 1: User|null}> $destinos */
        $destinos = match (true) {
            $quien->paciente !== null => [[$quien->paciente, $quien->cuenta]],
            $quien->cuenta !== null => [[null, $quien->cuenta]],
            default => $quien->candidatos->map(fn (Paciente $paciente): array => [$paciente, null])->values()->all(),
        };

        if (! $seco) {
            DB::transaction(function () use ($mensaje, $hash, $asunto, $quien, $motivo, $utiles, $destinos): void {
                foreach ($destinos as [$paciente, $cuenta]) {
                    $documento = new DocumentoPorRevisar([
                        'remitente' => $mensaje->remitente,
                        'asunto' => $mensaje->asunto,
                        'motivo' => $motivo,
                        'tipo_sugerido' => $asunto->tipo,
                        'fecha_recepcion' => $mensaje->fecha,
                    ]);
                    // De estos dos cuelga quién lo ve: no son fillable.
                    $documento->forceFill(['paciente_id' => $paciente?->id, 'usuario_id' => $cuenta?->id])->save();

                    $this->adjuntador->adjuntarContenidos($documento, $utiles, TipoAdjunto::Otro, $this->descripcion($mensaje, $cuenta ?? $paciente?->usuario));
                }

                $this->registrar($mensaje, $hash, ResultadoDeMail::PorRevisar, $quien->paciente);
            });
        }

        $this->responder(
            $mensaje->remitente,
            new RespuestaDelBuzon(ResultadoDeMail::PorRevisar, $asunto->tipo, $quien->paciente?->nombre, $motivo),
            $seco,
        );

        return ResultadoDeMail::PorRevisar;
    }

    /**
     * Cuenta los mails de este remitente de las últimas 24 horas (por el hash:
     * el remitente está cifrado).
     */
    private function excedioElTope(string $remitente): bool
    {
        $tope = (int) config('misalud.buzon.tope_por_remitente_por_dia');

        return MailRecibido::query()
            ->where('remitente_hash', MailRecibido::hashCiego($remitente))
            ->where('created_at', '>=', now()->subDay())
            ->count() >= $tope;
    }

    private function terminar(
        MensajeDeCasilla $mensaje,
        string $hash,
        ResultadoDeMail $resultado,
        ?Paciente $paciente,
        bool $seco,
        bool $guardarRemitente = true,
    ): ResultadoDeMail {
        if (! $seco) {
            $this->registrar($mensaje, $hash, $resultado, $paciente, $guardarRemitente);
        }

        return $resultado;
    }

    /**
     * ⚠️ De un remitente desconocido o de un mail automático no se guarda la
     * dirección ni el asunto: solo el hash del mensaje, para no procesarlo dos
     * veces.
     */
    private function registrar(
        MensajeDeCasilla $mensaje,
        string $hash,
        ResultadoDeMail $resultado,
        ?Paciente $paciente,
        bool $guardarRemitente = true,
    ): void {
        $fila = new MailRecibido([
            'remitente' => $guardarRemitente ? $mensaje->remitente : null,
            'asunto' => $guardarRemitente ? $mensaje->asunto : null,
            'resultado' => $resultado,
            'paciente_id' => $paciente?->id,
            'recibido_en' => $mensaje->fecha,
        ]);
        $fila->setAttribute('message_id_hash', $hash);
        $fila->save();
    }

    private function registrarError(MensajeDeCasilla $mensaje): void
    {
        try {
            $this->registrar($mensaje, MailRecibido::hashCiego($mensaje->identificador), ResultadoDeMail::Error, null, guardarRemitente: false);
        } catch (Throwable) {
            // Si ni siquiera se puede anotar, se reintenta en la corrida siguiente.
        }
    }

    /**
     * Solo a remitentes reconocidos (quien llama ya lo comprobó). Un fallo al
     * contestar no deshace lo guardado: el documento ya está en la ficha.
     */
    private function responder(string $remitente, RespuestaDelBuzon $respuesta, bool $seco): void
    {
        if ($seco) {
            return;
        }

        try {
            Mail::to($remitente)->send($respuesta);
        } catch (Throwable $error) {
            Log::warning('No se pudo contestar un mail del buzón.', ['excepcion' => $error::class]);
        }
    }

    /**
     * El día del documento es el día en que llegó el mail, en la zona de quien
     * lo recibe: un mail de las 22:00 en Argentina ya es "mañana" en UTC.
     */
    private function diaDelMail(MensajeDeCasilla $mensaje, ?User $quien): CarbonImmutable
    {
        $local = $quien?->enSuZona($mensaje->fecha) ?? $mensaje->fecha;

        return CarbonImmutable::parse($local->toDateString(), 'UTC')->startOfDay();
    }

    private function descripcion(MensajeDeCasilla $mensaje, ?User $quien): string
    {
        $local = $quien?->enSuZona($mensaje->fecha) ?? $mensaje->fecha;

        return 'Llegó por mail el '.$local->format('d/m/Y');
    }
}
