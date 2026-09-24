<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Se persiste SIEMPRE en UTC y se convierte al mostrar
|--------------------------------------------------------------------------
|
| Estos seis métodos son la única forma en que la app cruza entre la hora de
| una persona y la del servidor. Un error acá no rompe nada visible: corre
| las horas y ya.
|
*/

function usuarioEn(string $zona): User
{
    return new User(['name' => 'X', 'email' => 'x@x.test'])->forceFill(['zona_horaria' => $zona]);
}

it('un usuario nuevo arranca con la zona por defecto, sin tocar la base', function (): void {
    expect(new User()->zona_horaria)->toBe(User::ZONA_POR_DEFECTO);
});

it('interpreta lo que escribió la persona EN SU ZONA y devuelve UTC', function (): void {
    // Lo que manda un <input type="datetime-local"> no trae zona: son las
    // 23:30 "de ella". En Buenos Aires eso es el día siguiente en UTC.
    $utc = usuarioEn('America/Argentina/Buenos_Aires')->aUtc('2026-09-24T23:30');

    expect($utc?->format('Y-m-d H:i'))->toBe('2026-09-25 02:30')
        ->and($utc?->timezoneName)->toBe('UTC');
});

it('la misma hora escrita en otra zona da otro instante', function (): void {
    $bsAs = usuarioEn('America/Argentina/Buenos_Aires')->aUtc('2026-09-24T12:00');
    $madrid = usuarioEn('Europe/Madrid')->aUtc('2026-09-24T12:00');

    expect($bsAs?->format('H:i'))->toBe('15:00')
        ->and($madrid?->format('H:i'))->toBe('10:00');
});

it('devuelve un instante UTC a la zona de la persona', function (): void {
    $guardado = CarbonImmutable::parse('2026-09-25 02:30', 'UTC');

    $visible = usuarioEn('America/Argentina/Buenos_Aires')->enSuZona($guardado);

    expect($visible?->format('Y-m-d H:i'))->toBe('2026-09-24 23:30');
});

it('aUtc y enSuZona son ida y vuelta', function (): void {
    $usuario = usuarioEn('America/Argentina/Buenos_Aires');

    $vuelta = $usuario->enSuZona($usuario->aUtc('2026-09-24T23:30'));

    expect($vuelta?->format('Y-m-d\TH:i'))->toBe('2026-09-24T23:30');
});

it('null y vacío no son una fecha', function (): void {
    $usuario = usuarioEn('America/Argentina/Buenos_Aires');

    expect($usuario->aUtc(null))->toBeNull()
        ->and($usuario->aUtc(''))->toBeNull()
        ->and($usuario->enSuZona(null))->toBeNull();
});

it('una zona inválida cae al default en vez de tirar un 500', function (): void {
    // Una columna editada a mano, o un identificador que la IANA dio de
    // baja: la persona tiene que poder seguir usando la app.
    $usuario = usuarioEn('Marte/Olympus_Mons');

    expect($usuario->zona()->getName())->toBe(User::ZONA_POR_DEFECTO);
});

/*
|--------------------------------------------------------------------------
| hoy() vs hoyCalendario(): la confusión que corre las fechas tres horas
|--------------------------------------------------------------------------
*/

it('hoy() es el comienzo del día EN SU ZONA, como instante', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 02:30', 'UTC'));

    // 02:30 UTC del 25 son las 23:30 del 24 en Buenos Aires: el día de esta
    // persona todavía es el 24, y empezó a las 03:00 UTC.
    $hoy = usuarioEn('America/Argentina/Buenos_Aires')->hoy();

    expect($hoy->format('Y-m-d H:i'))->toBe('2026-09-24 00:00')
        ->and($hoy->utc()->format('Y-m-d H:i'))->toBe('2026-09-24 03:00');
});

it('hoyCalendario() es la misma FECHA pero a medianoche UTC', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 02:30', 'UTC'));

    /*
     * Para comparar contra una columna `date`, que Carbon lee siempre a
     * medianoche UTC. Usar `hoy()` contra un `date` corre la comparación
     * tres horas y hace que "mañana" se lea como "hoy".
     */
    $hoy = usuarioEn('America/Argentina/Buenos_Aires')->hoyCalendario();

    expect($hoy->format('Y-m-d H:i'))->toBe('2026-09-24 00:00')
        ->and($hoy->timezoneName)->toBe('UTC');
});

it('los dos coinciden en la fecha y NO en el instante', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 02:30', 'UTC'));
    $usuario = usuarioEn('America/Argentina/Buenos_Aires');

    expect($usuario->hoy()->toDateString())->toBe($usuario->hoyCalendario()->toDateString())
        ->and($usuario->hoy()->equalTo($usuario->hoyCalendario()))->toBeFalse();
});

it('ahora() responde en la zona de la persona', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 02:30', 'UTC'));

    expect(usuarioEn('America/Argentina/Buenos_Aires')->ahora()->format('Y-m-d H:i'))
        ->toBe('2026-09-24 23:30');
});
