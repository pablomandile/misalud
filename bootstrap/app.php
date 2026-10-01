<?php

use App\Console\Commands\CerrarTratamientosVencidos;
use App\Console\Commands\EnviarRecordatorios;
use App\Console\Commands\SincronizarRecetas;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HandleTamanioTexto;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'tamanio_texto']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleTamanioTexto::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        /*
         * Una vez al día alcanza: `activo` es informativo, no algo que
         * alguien necesite ver corregido al segundo. En producción lo
         * dispara `schedule:run` cada minuto por el cron de hPanel (ver
         * `deploy-hostinger`); acá solo se declara QUÉ correr y cuándo.
         */
        $schedule->command(CerrarTratamientosVencidos::class)->daily();

        /*
         * Cada HORA, no una vez al día: un recordatorio vence a cualquier
         * hora -la de su turno menos la anticipación-, así que un job diario
         * lo mandaría con hasta 24 horas de atraso. Para un aviso de 24 horas
         * de anticipación, eso es exactamente inútil.
         *
         * `withoutOverlapping()` es seguro barato: la transición de estado
         * (`Pendiente` → `Enviado`) ya evita mandar dos veces, pero dos
         * corridas simultáneas podrían leer la misma fila antes de que
         * ninguna la marque. Usa el lock de caché, y la tabla `cache_locks`
         * existe.
         */
        $schedule->command(EnviarRecordatorios::class)->hourly()->withoutOverlapping();

        /*
         * Cada hora también. Una receta sirve para ir a la farmacia, así que la
         * pregunta que contesta es "¿tengo una disponible ahora?", y la ventana
         * con solapamiento hace que una corrida de más cueste unas cabeceras.
         *
         * `withoutOverlapping()` acá importa más que en los otros dos: una
         * corrida puede tardar -abre una conexión IMAP por casilla y se baja los
         * adjuntos-, así que dos solapadas son plausibles de verdad. Las dos
         * importarían los mismos mails: la deduplicación por `message_id_hash` lo
         * frenaría, pero recién después de haber bajado y escrito los archivos
         * dos veces.
         */
        $schedule->command(SincronizarRecetas::class)->hourly()->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
