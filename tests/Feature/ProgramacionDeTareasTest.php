<?php

declare(strict_types=1);

use App\Models\Tratamiento;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| El scheduler corre cada tarea en su mismo proceso, sin lanzar otro PHP
|--------------------------------------------------------------------------
|
| `command()` lanza otro PHP con proc_open, y en el hosting compartido ese
| lanzamiento a veces lo rechaza el límite de procesos de la cuenta
| ("posix_spawn() failed: Operation not permitted"). Medido en producción:
| unas diez veces por día el buzón, y también los avisos; una tarea diaria
| que caía ahí se salteaba el día. Ver `bootstrap/app.php`.
*/

/**
 * @return array<string, Event>
 */
function tareasProgramadas(): array
{
    // `withSchedule()` declara las tareas recién cuando arranca la consola de
    // Artisan, que en un test no arranca sola.
    Artisan::all();

    $tareas = [];

    foreach (app(Schedule::class)->events() as $evento) {
        $tareas[(string) $evento->description] = $evento;
    }

    return $tareas;
}

it('ninguna tarea lanza otro proceso: todas corren adentro de schedule:run', function (): void {
    $tareas = tareasProgramadas();

    expect(array_keys($tareas))->toEqualCanonicalizing([
        'misalud:cerrar-tratamientos-vencidos',
        'misalud:renovar-aplicaciones-anuales',
        'misalud:vaciar-papelera',
        'misalud:enviar-recordatorios',
        'misalud:procesar-buzon',
        'demo:reset',
    ]);

    foreach ($tareas as $nombre => $evento) {
        expect($evento)->toBeInstanceOf(CallbackEvent::class, "{$nombre} lanza otro proceso")
            ->and($evento->command)->toBeNull();
    }
});

it('mantiene la frecuencia de cada tarea', function (): void {
    $tareas = tareasProgramadas();

    expect($tareas['misalud:procesar-buzon']->expression)->toBe('*/5 * * * *')
        ->and($tareas['misalud:enviar-recordatorios']->expression)->toBe('0 * * * *')
        ->and($tareas['misalud:cerrar-tratamientos-vencidos']->expression)->toBe('0 0 * * *')
        ->and($tareas['misalud:vaciar-papelera']->expression)->toBe('0 0 * * *')
        ->and($tareas['demo:reset']->expression)->toBe('0 7 * * *')
        ->and($tareas['misalud:procesar-buzon']->withoutOverlapping)->toBeTrue()
        ->and($tareas['misalud:enviar-recordatorios']->withoutOverlapping)->toBeTrue();
});

it('la tarea corre el comando de verdad, en el mismo proceso', function (): void {
    $vencido = Tratamiento::factory()->create([
        'activo' => true,
        'inicio' => now()->subMonths(2)->startOfDay(),
        'fin' => now()->subDays(3)->startOfDay(),
    ]);

    tareasProgramadas()['misalud:cerrar-tratamientos-vencidos']->run(app());

    expect($vencido->fresh()?->activo)->toBeFalse();
});

it('fuera de la demo no corre el reset, y en la demo no corren el buzón ni los avisos', function (): void {
    $tareas = tareasProgramadas();

    config(['demo.enabled' => false]);
    expect($tareas['demo:reset']->filtersPass(app()))->toBeFalse()
        ->and($tareas['misalud:procesar-buzon']->filtersPass(app()))->toBeTrue();

    config(['demo.enabled' => true]);
    expect($tareas['demo:reset']->filtersPass(app()))->toBeTrue()
        ->and($tareas['misalud:procesar-buzon']->filtersPass(app()))->toBeFalse()
        ->and($tareas['misalud:enviar-recordatorios']->filtersPass(app()))->toBeFalse();
});
