<?php

declare(strict_types=1);

use App\Models\Estudio;
use App\Models\OrdenEstudio;
use App\Models\PrescripcionOcular;
use App\Models\RecetaMedica;
use App\Models\RegistroEnfermedad;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Las factories no fabrican fechas "futuras" de noche
|--------------------------------------------------------------------------
|
| El CI falló una vez en `RecetaMedicaControllerTest`: "La fecha no puede ser
| futura". La factory ponía `now()->subDays(0..10)`, en UTC, y el test corrió
| a las 22:53 de Argentina: cuando salía 0, la fecha era el día SIGUIENTE para
| la cuenta, y `FechaNoFutura` -que compara contra `hoyCalendario()`- la
| rechazaba. Es la trampa de `hoy()` contra `hoyCalendario()`, dentro de una
| factory, y fallaba solo entre las 21:00 y la medianoche, una vez de cada
| tantas.
|
| Se fija a las 22:53 de Argentina y se generan varias de cada una: con el
| rango que arrancaba en 0, alguna caía en el futuro.
*/

it('ninguna fecha de calendario de una factory cae en el futuro de la cuenta', function (string $modelo): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 01:53:00', 'UTC'));

    $hoyDeLaCuenta = User::factory()->make()->hoyCalendario()->toDateString();

    for ($i = 0; $i < 60; $i++) {
        $fecha = $modelo::factory()->make()->fecha->toDateString();

        expect($fecha <= $hoyDeLaCuenta)->toBeTrue("{$modelo} fabricó {$fecha}, y para la cuenta hoy es {$hoyDeLaCuenta}");
    }
})->with([
    RecetaMedica::class,
    Estudio::class,
    OrdenEstudio::class,
    PrescripcionOcular::class,
    RegistroEnfermedad::class,
]);
