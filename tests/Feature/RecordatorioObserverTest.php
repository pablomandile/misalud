<?php

declare(strict_types=1);

use App\Enums\EstadoRecordatorio;
use App\Enums\EstadoTurno;
use App\Enums\TipoRecordatorio;
use App\Models\Medicamento;
use App\Models\Paciente;
use App\Models\Recordatorio;
use App\Models\Tratamiento;
use App\Models\Turno;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * El contrato de `GeneradorDeRecordatorios`, que es lo que hace confiable a
 * toda la tabla: un observer corre en CADA guardado de su origen, así que
 * "generar el recordatorio" tiene que poder repetirse sin consecuencias.
 */
beforeEach(function (): void {
    // Fija el reloj: las fechas de los recordatorios son aritmética sobre
    // "ahora", y un test que dependa del reloj real falla un día cualquiera.
    Carbon::setTestNow('2026-10-01 12:00:00');
});

/*
|--------------------------------------------------------------------------
| Un turno genera su aviso
|--------------------------------------------------------------------------
*/

it('crear un turno genera su recordatorio 24 horas antes', function (): void {
    $turno = Turno::factory()->create(['fecha_hora' => '2026-10-20 15:30:00']);

    $recordatorio = Recordatorio::sole();

    expect($recordatorio->tipo)->toBe(TipoRecordatorio::TurnoProximo)
        ->and($recordatorio->estado)->toBe(EstadoRecordatorio::Pendiente)
        ->and($recordatorio->paciente_id)->toBe($turno->paciente_id)
        ->and($recordatorio->origen_id)->toBe($turno->id)
        ->and($recordatorio->origen_type)->toBe(Turno::class)
        // 24 horas antes del turno, al instante exacto.
        ->and($recordatorio->fecha->format('Y-m-d H:i:s'))->toBe('2026-10-19 15:30:00');
});

it('guardar el mismo turno muchas veces NO duplica el recordatorio', function (): void {
    // Es literalmente lo que hace un observer: correr en cada save().
    $turno = Turno::factory()->create(['fecha_hora' => '2026-10-20 15:30:00']);

    $turno->save();
    $turno->save();
    $turno->touch();

    expect(Recordatorio::count())->toBe(1);
});

it('la base misma impide dos recordatorios del mismo origen y tipo', function (): void {
    /*
     * El UNIQUE no está de adorno: convierte un bug de lógica en un error
     * ruidoso en vez de una bandeja con el mismo aviso repetido.
     */
    $turno = Turno::factory()->create();
    $copia = new Recordatorio;
    $copia->forceFill([
        'paciente_id' => $turno->paciente_id,
        'tipo' => TipoRecordatorio::TurnoProximo,
        'fecha' => now(),
        'origen_type' => Turno::class,
        'origen_id' => $turno->id,
        'estado' => EstadoRecordatorio::Pendiente,
    ]);

    expect(fn () => $copia->save())->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| ⚠️ Las dos reglas que definen el diseño
|--------------------------------------------------------------------------
*/

it('editar el motivo NO reabre un recordatorio que la persona ya marcó hecho', function (): void {
    /*
     * Es la mitad más fácil de arruinar: si el observer reseteara el estado
     * en cada guardado, corregir una falta de ortografía en el motivo haría
     * reaparecer como pendiente un aviso que la persona ya resolvió.
     */
    $turno = Turno::factory()->create(['fecha_hora' => '2026-10-20 15:30:00']);
    Recordatorio::sole()->completar();

    $turno->update(['motivo' => 'Control anual, ahora bien escrito']);

    $recordatorio = Recordatorio::sole();

    expect($recordatorio->estado)->toBe(EstadoRecordatorio::Completado)
        ->and($recordatorio->fecha_completado)->not->toBeNull();
});

it('mover el turno de fecha SÍ reabre el recordatorio y lo deja para avisar de nuevo', function (): void {
    /*
     * La otra mitad, y es igual de deliberada: el "ya lo sé" que la persona
     * dio antes era sobre otra fecha, y el mail que se mandó decía un día que
     * ya no es.
     */
    $turno = Turno::factory()->create(['fecha_hora' => '2026-10-20 15:30:00']);
    $recordatorio = Recordatorio::sole();
    $recordatorio->forceFill(['enviado_en' => now(), 'estado' => EstadoRecordatorio::Enviado])->save();
    $recordatorio->completar();

    $turno->update(['fecha_hora' => '2026-10-25 09:00:00']);

    $recordatorio = Recordatorio::sole();

    expect($recordatorio->estado)->toBe(EstadoRecordatorio::Pendiente)
        ->and($recordatorio->enviado_en)->toBeNull()
        ->and($recordatorio->fecha_completado)->toBeNull()
        ->and($recordatorio->fecha->format('Y-m-d H:i:s'))->toBe('2026-10-24 09:00:00');
});

/*
|--------------------------------------------------------------------------
| Cuándo deja de corresponder un aviso
|--------------------------------------------------------------------------
*/

it('cancelar el turno borra su recordatorio', function (): void {
    // Cancelar y borrar terminan en el mismo lugar, y por eso hay una sola
    // regla en vez de dos (ver `TurnoObserver`).
    $turno = Turno::factory()->create();
    expect(Recordatorio::count())->toBe(1);

    $turno->update(['estado' => EstadoTurno::Cancelado]);

    expect(Recordatorio::count())->toBe(0);
});

it('un turno cancelado no genera recordatorio ni al crearse', function (): void {
    Turno::factory()->cancelado()->create();

    expect(Recordatorio::count())->toBe(0);
});

it('mandar el turno a la papelera borra su recordatorio, y restaurarlo lo devuelve', function (): void {
    $turno = Turno::factory()->create(['fecha_hora' => '2026-10-20 15:30:00']);

    $turno->delete();
    expect(Recordatorio::count())->toBe(0);

    $turno->restore();

    expect(Recordatorio::count())->toBe(1)
        ->and(Recordatorio::sole()->fecha->format('Y-m-d H:i:s'))->toBe('2026-10-19 15:30:00');
});

/*
|--------------------------------------------------------------------------
| El segundo origen: tratamientos
|--------------------------------------------------------------------------
*/

it('un tratamiento activo con fecha de fin avisa 24 horas antes', function (): void {
    $tratamiento = Tratamiento::factory()->create([
        'inicio' => '2026-10-01',
        'fin' => '2026-10-15',
        'activo' => true,
    ]);

    $recordatorio = Recordatorio::sole();

    expect($recordatorio->tipo)->toBe(TipoRecordatorio::TratamientoTermina)
        ->and($recordatorio->origen_type)->toBe(Tratamiento::class)
        ->and($recordatorio->origen_id)->toBe($tratamiento->id)
        /*
         * ⚠️ `tratamientos.fin` es una columna `date`: Carbon la lee a
         * medianoche UTC, así que 24 horas antes es la medianoche UTC del día
         * anterior -las 21:00 en Argentina-. Sale de la aritmética, no de una
         * hora elegida; ver `TipoRecordatorio`.
         */
        ->and($recordatorio->fecha->format('Y-m-d H:i:s'))->toBe('2026-10-14 00:00:00');
});

it('un tratamiento sin fecha de fin no avisa nada', function (): void {
    // No termina nunca: no hay nada que recordar.
    Tratamiento::factory()->create(['fin' => null, 'activo' => true]);

    expect(Recordatorio::count())->toBe(0);
});

it('un tratamiento ya inactivo no avisa, y desactivarlo borra el aviso', function (): void {
    $tratamiento = Tratamiento::factory()->create([
        'inicio' => '2026-10-01',
        'fin' => '2026-10-15',
        'activo' => true,
    ]);
    expect(Recordatorio::count())->toBe(1);

    $tratamiento->update(['activo' => false]);

    expect(Recordatorio::count())->toBe(0);
});

it('los dos orígenes conviven en la misma tabla sin pisarse', function (): void {
    /*
     * Es para lo que existe la clave (origen_type, origen_id, tipo): dos
     * filas con el MISMO id numérico pero de tablas distintas.
     */
    $paciente = Paciente::factory()->create();
    Turno::factory()->for($paciente)->create(['fecha_hora' => '2026-10-20 15:30:00']);
    Tratamiento::factory()->for($paciente)->for(Medicamento::factory())->create([
        'inicio' => '2026-10-01',
        'fin' => '2026-10-15',
        'activo' => true,
    ]);

    expect(Recordatorio::count())->toBe(2)
        ->and($paciente->recordatorios()->count())->toBe(2)
        ->and(Recordatorio::query()->pluck('origen_type')->unique()->count())->toBe(2);
});

it('borrar el paciente se lleva sus recordatorios', function (): void {
    // `cascadeOnDelete` sobre `paciente_id`: no quedan avisos de una ficha
    // que ya no existe.
    $paciente = Paciente::factory()->create();
    Turno::factory()->for($paciente)->create();
    expect(Recordatorio::count())->toBe(1);

    $paciente->forceDelete();

    expect(Recordatorio::count())->toBe(0);
});
