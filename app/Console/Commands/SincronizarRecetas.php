<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CuentaMail;
use App\Services\SincronizadorDeRecetas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trae de cada casilla las recetas que llegaron por mail.
 *
 * ## Corre cada hora
 *
 * Una receta sirve para ir a la farmacia, así que la pregunta es "¿tengo una
 * disponible ahora?". Un job diario contestaría eso con hasta 24 horas de atraso
 * sobre una vigencia de 30 días: no es grave, pero tampoco cuesta nada hacerlo
 * bien, y la ventana con solapamiento hace que una corrida de más sea casi
 * gratis.
 *
 * ## ⚠️ Una casilla caída no puede llevarse las demás
 *
 * El `try`/`catch` va **por casilla**, igual que el de
 * `misalud:enviar-recordatorios` va por destinatario. Una contraseña de
 * aplicación revocada es el caso más probable de todos —se revocan solas cuando
 * alguien cambia la clave de su cuenta de Google— y no tiene por qué dejar sin
 * importar a las otras casillas.
 *
 * Adentro de cada casilla, `SincronizadorDeRecetas` vuelve a aislar **por
 * mensaje**: un mail roto tampoco se lleva la tanda de su propia casilla.
 */
class SincronizarRecetas extends Command
{
    protected $signature = 'misalud:sincronizar-recetas
        {--casilla= : Solo esta casilla, por id}
        {--seco : Muestra qué importaría, sin guardar nada}';

    protected $description = 'Importa de las casillas configuradas las recetas que llegaron por mail';

    public function handle(SincronizadorDeRecetas $sincronizador): int
    {
        $seco = (bool) $this->option('seco');
        $id = $this->option('casilla');

        $casillas = CuentaMail::query()
            // Explícito: el sincronizador crea las recetas por la relación del
            // usuario, así que sin esto es una consulta por casilla.
            ->with('usuario')
            ->when(is_string($id) && $id !== '', fn ($consulta) => $consulta->whereKey((int) $id))
            ->orderBy('id')
            ->get();

        if ($casillas->isEmpty()) {
            $this->components->info('No hay casillas configuradas.');

            return self::SUCCESS;
        }

        $conError = 0;

        foreach ($casillas as $casilla) {
            try {
                $resumen = $sincronizador->sincronizar($casilla, $seco);

                $this->components->twoColumnDetail(
                    ($seco ? '[seco] ' : '').'casilla '.$casilla->id,
                    $resumen->resumen(),
                );
            } catch (Throwable $e) {
                $conError++;

                /*
                 * El mensaje de la excepción NO se imprime ni se loguea tal cual:
                 * puede venir del servidor IMAP y traer partes del comando que lo
                 * provocó. `ProbadorDeCasilla` tiene el detalle de por qué, y es
                 * la pantalla de la casilla la que sirve para diagnosticarlo con
                 * un mensaje que se entiende.
                 */
                $this->components->error(sprintf(
                    'La casilla %d falló (%s). Probá la conexión desde la pantalla de la casilla.',
                    $casilla->id,
                    class_basename($e),
                ));

                Log::error('Falló la sincronización de una casilla', [
                    'cuenta_mail_id' => $casilla->id,
                    'usuario_id' => $casilla->usuario_id,
                    'excepcion' => $e::class,
                ]);
            }
        }

        return $conError === 0 ? self::SUCCESS : self::FAILURE;
    }
}
