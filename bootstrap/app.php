<?php

use App\Console\Commands\CerrarTratamientosVencidos;
use App\Console\Commands\DemoReset;
use App\Console\Commands\EnviarRecordatorios;
use App\Console\Commands\ProcesarBuzon;
use App\Console\Commands\RenovarAplicacionesAnuales;
use App\Console\Commands\VaciarPapelera;
use App\Http\Middleware\AceptoTerminos;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HandleTamanioTexto;
use App\Http\Middleware\ProtegerDemo;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

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
            // Solo con APP_DEMO: la cuenta compartida no se cambia y no salen mails.
            ProtegerDemo::class,
            // Frena a quien no aceptó los términos vigentes (ver EXENTAS).
            AceptoTerminos::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        /*
         * ⚠️ Cada tarea corre ADENTRO del proceso de `schedule:run`, con
         * `call()` + `Artisan::call()`, y no con `command()`. `command()` lanza
         * otro PHP con `proc_open`, y en el hosting compartido ese lanzamiento
         * a veces lo rechaza el límite de procesos de la cuenta
         * (`posix_spawn() failed: Operation not permitted`): medido en
         * producción, unas diez veces por día solo el buzón, y también los
         * avisos. Una tarea diaria que caía en ese rechazo se salteaba el día
         * entero. En el mismo proceso no hay nada que lanzar. Lo fija
         * `ProgramacionDeTareasTest`.
         *
         * Devuelve si terminó bien: un `false` es lo que el scheduler toma
         * como falla de una tarea de este tipo.
         *
         * @param  class-string  $comando
         */
        $enProceso = static fn (string $comando): Closure => static fn (): bool => Artisan::call($comando) === Command::SUCCESS;

        /*
         * Una vez al día alcanza: `activo` es informativo, no algo que
         * alguien necesite ver corregido al segundo. En producción lo
         * dispara `schedule:run` cada minuto por el cron de hPanel (ver
         * `deploy-hostinger`); acá solo se declara QUÉ correr y cuándo.
         */
        $schedule->call($enProceso(CerrarTratamientosVencidos::class))->name('misalud:cerrar-tratamientos-vencidos')->daily();

        /*
         * Una vez al día: pasada la aplicación de un medicamento de "una vez
         * por año", sus dos avisos se mueven al año siguiente.
         */
        $schedule->call($enProceso(RenovarAplicacionesAnuales::class))->name('misalud:renovar-aplicaciones-anuales')->daily();

        /*
         * Una vez al día: lo que lleva más de 30 días en la papelera se borra
         * de verdad, con sus archivos. Es lo que promete la política de
         * privacidad (ver VaciarPapelera::DIAS).
         */
        $schedule->call($enProceso(VaciarPapelera::class))->name('misalud:vaciar-papelera')->daily();

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
        $schedule->call($enProceso(EnviarRecordatorios::class))->name('misalud:enviar-recordatorios')->hourly()->withoutOverlapping()
            ->when(fn (): bool => ! config('demo.enabled'));

        /*
         * El buzón de la app, cada cinco minutos: quien manda una receta espera
         * la respuesta, y una hora de silencio se lee como "no llegó".
         * `withoutOverlapping()` porque una corrida baja adjuntos y puede
         * tardar; dos solapadas leerían los mismos mails (los frenaría
         * `mails_recibidos`, pero recién después de bajarlos dos veces).
         */
        $schedule->call($enProceso(ProcesarBuzon::class))->name('misalud:procesar-buzon')->everyFiveMinutes()->withoutOverlapping()
            ->when(fn (): bool => ! config('demo.enabled'));

        /*
         * Solo en la demo (APP_DEMO=true): vuelve a dejarla como nueva todas
         * las noches, a las 04:00 de Argentina (la app corre en UTC). La demo
         * tampoco lee ningún buzón ni manda avisos (los dos `unless` de arriba).
         */
        $schedule->call($enProceso(DemoReset::class))->name('demo:reset')->dailyAt('07:00')
            ->when(fn (): bool => (bool) config('demo.enabled'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
