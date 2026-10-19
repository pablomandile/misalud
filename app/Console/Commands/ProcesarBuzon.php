<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ProcesadorDeBuzon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lee el buzón de la app y guarda cada documento en la ficha que corresponde
 * (ver `ProcesadorDeBuzon`).
 *
 * Corre cada cinco minutos: quien manda un documento espera la respuesta, y una
 * hora de silencio se lee como "no llegó". Con `withoutOverlapping()`: una
 * corrida baja adjuntos y puede tardar.
 *
 * ⚠️ Si el buzón no responde (contraseña cambiada, servidor caído) el comando
 * termina con FAILURE y lo deja en el log, pero sin el mensaje de la excepción:
 * puede venir del servidor IMAP y traer partes del LOGIN.
 */
class ProcesarBuzon extends Command
{
    protected $signature = 'misalud:procesar-buzon
        {--seco : Muestra qué haría con cada mail, sin guardar, contestar ni mover nada}';

    protected $description = 'Guarda en cada ficha las recetas, órdenes y estudios que llegaron al buzón';

    public function handle(ProcesadorDeBuzon $procesador): int
    {
        try {
            $resumen = $procesador->procesar((bool) $this->option('seco'));
        } catch (Throwable $error) {
            Log::error('No se pudo leer el buzón.', ['excepcion' => $error::class]);
            $this->error('No se pudo leer el buzón. Revisá BUZON_* en el .env.');

            return self::FAILURE;
        }

        if ($resumen === []) {
            $this->info('No hay mails nuevos.');

            return self::SUCCESS;
        }

        foreach ($resumen as $resultado => $cantidad) {
            $this->line("{$resultado}: {$cantidad}");
        }

        return self::SUCCESS;
    }
}
