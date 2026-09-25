<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EstadoRecordatorio;
use App\Mail\AvisoDeRecordatorio;
use App\Models\Paciente;
use App\Models\Recordatorio;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Manda los avisos que ya llegaron a su hora.
 *
 * ## Corre cada HORA, no una vez al día
 *
 * Un recordatorio vence a cualquier hora —la de su turno menos la
 * anticipación—, así que un job diario lo mandaría con hasta 24 horas de
 * atraso, que para un aviso de 24 horas de anticipación es exactamente
 * inútil.
 *
 * ## ⚠️ No se avisa de algo que ya pasó, y el corte no es una constante
 *
 * Si el servidor estuvo caído una semana, mandar "tenés un turno" por un
 * turno que ya fue es peor que no mandar nada: no se puede actuar y encima
 * hace desconfiar del resto de los avisos.
 *
 * La tentación era una "ventana de gracia" de N horas. No hace falta: el
 * corte **se deduce del modelo**. `Recordatorio::instanteDelEvento()` es
 * `fecha + anticipación`, así que la condición es literalmente *"¿el evento
 * ya pasó?"*. Sin número mágico, y sin que cambiar la anticipación de un tipo
 * deje el corte desalineado.
 *
 * Esos quedan en `Vencido`: no se mandan, pero **tampoco se pierden** —la
 * pantalla puede distinguir "no te avisamos" de "te avisamos y no lo
 * resolviste"—.
 *
 * ## Un fallo no se lleva la tanda
 *
 * El `try`/`catch` va **por destinatario**: un mail rebotado no puede impedir
 * que los demás salgan, ni que el resto de los recordatorios se procesen.
 *
 * ⚠️ Un recordatorio se marca `Enviado` si salió **al menos uno** de sus
 * mails. Es una decisión con filo: si eran dos destinatarios y el segundo
 * falló, esa persona se queda sin aviso. La alternativa —no marcarlo y
 * reintentar— le mandaría al primero el mismo mail cada hora hasta que el
 * evento pase, hasta veinticuatro veces. Entre perder un aviso y volverse
 * insoportable, gana lo primero; el fallo queda en el log con el id del
 * usuario para poder ir a buscarlo.
 */
class EnviarRecordatorios extends Command
{
    protected $signature = 'misalud:enviar-recordatorios {--seco : Muestra qué haría, sin mandar ni escribir nada}';

    protected $description = 'Manda por mail los recordatorios que ya llegaron a su hora';

    public function handle(): int
    {
        $seco = (bool) $this->option('seco');
        $ahora = CarbonImmutable::now();

        $pendientes = Recordatorio::query()
            ->where('estado', EstadoRecordatorio::Pendiente)
            ->where('fecha', '<=', $ahora)
            // Explícito: sin esto es una consulta por cada aviso, y otra por
            // cada pivote, para resolver a quién le corresponde.
            ->with(['paciente.cuidadores'])
            ->orderBy('fecha')
            ->get();

        if ($pendientes->isEmpty()) {
            $this->components->info('No había recordatorios para mandar.');

            return self::SUCCESS;
        }

        $enviados = 0;
        $vencidos = 0;
        $sinDestinatarios = 0;

        foreach ($pendientes as $recordatorio) {
            if ($recordatorio->instanteDelEvento()->lessThan($ahora)) {
                $vencidos++;

                if (! $seco) {
                    $recordatorio->setAttribute('estado', EstadoRecordatorio::Vencido);
                    $recordatorio->save();
                }

                continue;
            }

            $destinatarios = $this->destinatarios($recordatorio->paciente);

            if ($destinatarios->isEmpty()) {
                /*
                 * Puede pasar y no es un error: una ficha cuyo único usuario
                 * todavía no verificó su mail. Se deja `Pendiente` para que
                 * la próxima corrida lo intente de nuevo -y si nunca se
                 * verifica, vencerá solo-.
                 */
                $sinDestinatarios++;

                continue;
            }

            if ($seco) {
                /*
                 * La hora va etiquetada como UTC **a propósito**: en un
                 * ensayo no hay un destinatario del que tomar la zona, así
                 * que se muestra la de la base. Sin la etiqueta, alguien que
                 * compare esta salida con el mail que llega -que sí va en la
                 * zona de cada uno- creería que se contradicen.
                 */
                $this->line(sprintf(
                    '  avisaría a %d destinatario(s): %s del %s UTC',
                    $destinatarios->count(),
                    $recordatorio->tipo->etiqueta(),
                    $recordatorio->instanteDelEvento()->format('d/m/Y H:i'),
                ));
                $enviados++;

                continue;
            }

            if ($this->avisar($recordatorio, $destinatarios)) {
                $recordatorio->setAttribute('estado', EstadoRecordatorio::Enviado);
                $recordatorio->setAttribute('enviado_en', $ahora);
                $recordatorio->save();
                $enviados++;
            }
        }

        $this->components->info(sprintf(
            '%s%d aviso(s), %d vencido(s) sin mandar, %d sin destinatario verificado.',
            $seco ? '[seco] ' : '',
            $enviados,
            $vencidos,
            $sinDestinatarios,
        ));

        return self::SUCCESS;
    }

    /**
     * Manda un mail por destinatario. Devuelve `true` si salió al menos uno.
     *
     * @param  Collection<int, User>  $destinatarios
     */
    private function avisar(Recordatorio $recordatorio, Collection $destinatarios): bool
    {
        $algunoSalio = false;

        foreach ($destinatarios as $usuario) {
            try {
                Mail::to($usuario->email)->send(new AvisoDeRecordatorio($recordatorio, $usuario));
                $algunoSalio = true;
            } catch (Throwable $e) {
                /*
                 * ⚠️ **Al log NO va ningún dato clínico**, ni el nombre del
                 * paciente ni la dirección de mail: los ids alcanzan para
                 * encontrar a quién le falló, y un log es un archivo de texto
                 * que termina en cualquier lado. Misma regla que los errores
                 * de Socialite.
                 */
                Log::error('No se pudo mandar un recordatorio', [
                    'recordatorio_id' => $recordatorio->id,
                    'usuario_id' => $usuario->id,
                    'excepcion' => $e::class,
                    'mensaje' => $e->getMessage(),
                ]);
            }
        }

        return $algunoSalio;
    }

    /**
     * Quién recibe el aviso de esta ficha.
     *
     * Dos filtros, cada uno con su motivo:
     *
     * - **solo quien puede editar** (propietario y cuidador): un `Lector` no
     *   puede hacer nada con el aviso —no agenda, no cancela, no marca
     *   hecho—, así que para él sería solo correo;
     * - **solo mails verificados**: un mail sin verificar es una dirección
     *   que nadie probó que sea suya, y puede ser un tipeo que apunta a un
     *   tercero. La app ya exige verificarlo para entrar; mandar un aviso con
     *   el nombre de un paciente a una dirección no probada sería el único
     *   lugar donde eso no se respeta.
     *
     * @return Collection<int, User>
     */
    private function destinatarios(?Paciente $paciente): Collection
    {
        if ($paciente === null) {
            return collect();
        }

        return $paciente->cuidadores
            ->filter(fn (User $usuario): bool => ($paciente->rolDe($usuario)?->puedeEditar() ?? false)
                && $usuario->hasVerifiedEmail()
            )
            ->values();
    }
}
