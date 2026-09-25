<?php

declare(strict_types=1);

use App\Enums\EstadoRecordatorio;
use App\Enums\EstadoTurno;
use App\Enums\RolPaciente;
use App\Models\Medico;
use App\Models\OrdenEstudio;
use App\Models\Paciente;
use App\Models\Recordatorio;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @return array{0: User, 1: Paciente}
 */
function fichaConTurnos(string $zona = 'America/Argentina/Buenos_Aires'): array
{
    $usuario = User::factory()->create(['zona_horaria' => $zona]);
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    return [$usuario, $paciente];
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-01 12:00:00');
});

/*
|--------------------------------------------------------------------------
| Alta, y la zona horaria
|--------------------------------------------------------------------------
*/

it('agenda un turno futuro, que es el caso normal', function (): void {
    [$usuario, $paciente] = fichaConTurnos();

    $this->actingAs($usuario)
        ->post(route('pacientes.turnos.store', $paciente), [
            'fecha_hora' => '2026-10-20T15:30',
            'estado' => EstadoTurno::Programado->value,
            'motivo' => 'Control anual',
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    $turno = Turno::sole();

    expect($turno->paciente_id)->toBe($paciente->id)
        ->and($turno->motivo)->toBe('Control anual')
        ->and($turno->estado)->toBe(EstadoTurno::Programado);
});

it('guarda la hora en UTC y la devuelve idéntica en la zona de la cuenta', function (): void {
    /*
     * El viaje completo: un `datetime-local` no trae zona, así que lo que
     * llega es hora de Buenos Aires (UTC-3). Guardarlo tal cual correría el
     * turno tres horas sin ningún síntoma hasta que alguien mire la hora.
     */
    [$usuario, $paciente] = fichaConTurnos();

    $this->actingAs($usuario)->post(route('pacientes.turnos.store', $paciente), [
        'fecha_hora' => '2026-10-20T15:30',
        'estado' => EstadoTurno::Programado->value,
    ]);

    $crudo = DB::table('turnos')->where('id', Turno::sole()->id)->first();

    // En la base, UTC: las 15:30 de Buenos Aires son las 18:30 UTC.
    expect($crudo->fecha_hora)->toStartWith('2026-10-20 18:30');

    // Y de vuelta en pantalla, las 15:30 otra vez.
    $this->actingAs($usuario)
        ->get(route('pacientes.turnos.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->where('proximos.0.fechaVisible', '20/10/2026 15:30')
            ->where('proximos.0.fechaLocal', '2026-10-20T15:30')
        );
});

it('acepta un turno PASADO: sirve para registrar que se fue', function (): void {
    // Es lo contrario de una medición, que rechaza el futuro. Acá no hay
    // ninguna regla de rango, a propósito.
    [$usuario, $paciente] = fichaConTurnos();

    $this->actingAs($usuario)
        ->post(route('pacientes.turnos.store', $paciente), [
            'fecha_hora' => '2025-03-10T09:00',
            'estado' => EstadoTurno::Asistido->value,
        ])
        ->assertSessionHasNoErrors();

    expect(Turno::count())->toBe(1);
});

it('NO deja elegir en la ficha de quién escribir', function (): void {
    [$usuario, $paciente] = fichaConTurnos();
    $ajeno = Paciente::factory()->create();

    $this->actingAs($usuario)->post(route('pacientes.turnos.store', $paciente), [
        'paciente_id' => $ajeno->id,
        'fecha_hora' => '2026-10-20T15:30',
        'estado' => EstadoTurno::Programado->value,
    ]);

    expect(Turno::sole()->paciente_id)->toBe($paciente->id);
});

it('cifra el motivo y deja el estado y la fecha en claro', function (): void {
    $turno = Turno::factory()->create(['motivo' => 'Consulta Reservada']);

    $crudo = DB::table('turnos')->where('id', $turno->id)->first();

    expect($crudo->motivo)->not->toContain('Reservada')
        // El estado y la fecha ordenan la agenda: van en claro.
        ->and($crudo->estado)->toBe('programado')
        ->and($crudo->fecha_hora)->not->toBeNull()
        ->and($turno->fresh()->motivo)->toBe('Consulta Reservada');
});

/*
|--------------------------------------------------------------------------
| Validación
|--------------------------------------------------------------------------
*/

it('exige cuándo y en qué estado', function (): void {
    [$usuario, $paciente] = fichaConTurnos();

    $this->actingAs($usuario)
        ->post(route('pacientes.turnos.store', $paciente), [])
        ->assertSessionHasErrors(['fecha_hora', 'estado']);
});

it('rechaza un estado inventado', function (): void {
    [$usuario, $paciente] = fichaConTurnos();

    $this->actingAs($usuario)
        ->post(route('pacientes.turnos.store', $paciente), [
            'fecha_hora' => '2026-10-20T15:30',
            'estado' => 'quizas',
        ])
        ->assertSessionHasErrors('estado');
});

it('no deja elegir un médico que no es de este usuario', function (): void {
    [$usuario, $paciente] = fichaConTurnos();
    $ajeno = Medico::factory()->create(['usuario_id' => User::factory()]);

    $this->actingAs($usuario)
        ->post(route('pacientes.turnos.store', $paciente), [
            'fecha_hora' => '2026-10-20T15:30',
            'estado' => EstadoTurno::Programado->value,
            'medico_id' => $ajeno->id,
        ])
        ->assertSessionHasErrors('medico_id');
});

it('no deja colgar el turno de una orden de otra ficha', function (): void {
    /*
     * Sin esta condición, un id ajeno vincularía el turno a la orden de otra
     * persona y nada lo delataría en pantalla.
     */
    [$usuario, $paciente] = fichaConTurnos();
    $ordenAjena = OrdenEstudio::factory()->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.turnos.store', $paciente), [
            'fecha_hora' => '2026-10-20T15:30',
            'estado' => EstadoTurno::Programado->value,
            'orden_estudio_id' => $ordenAjena->id,
        ])
        ->assertSessionHasErrors('orden_estudio_id');
});

it('sí deja colgarlo de una orden de la misma ficha', function (): void {
    [$usuario, $paciente] = fichaConTurnos();
    $orden = OrdenEstudio::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.turnos.store', $paciente), [
            'fecha_hora' => '2026-10-20T15:30',
            'estado' => EstadoTurno::Programado->value,
            'orden_estudio_id' => $orden->id,
        ])
        ->assertSessionHasNoErrors();

    expect(Turno::sole()->orden_estudio_id)->toBe($orden->id);
});

/*
|--------------------------------------------------------------------------
| La agenda: lo que viene primero
|--------------------------------------------------------------------------
*/

it('pone lo que VIENE primero, del más próximo al más lejano', function (): void {
    /*
     * Al revés que todo el resto de la app: una agenda muestra futuro, y un
     * turno de mañana no puede quedar debajo de uno de diciembre.
     */
    [$usuario, $paciente] = fichaConTurnos();
    Turno::factory()->for($paciente)->create(['fecha_hora' => '2026-12-20 10:00']);
    Turno::factory()->for($paciente)->create(['fecha_hora' => '2026-10-05 10:00']);
    Turno::factory()->for($paciente)->asistido()->create(['fecha_hora' => '2026-09-01 10:00']);

    $this->actingAs($usuario)
        ->get(route('pacientes.turnos.index', $paciente))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('turnos/Index')
            ->has('proximos', 2)
            ->has('pasados', 1)
            // El más próximo arriba.
            ->where('proximos.0.fechaVisible', '05/10/2026 07:00')
            ->where('proximos.1.fechaVisible', '20/12/2026 07:00')
        );
});

it('manda los avisos abiertos y no los completados', function (): void {
    [$usuario, $paciente] = fichaConTurnos();
    Turno::factory()->for($paciente)->create(['fecha_hora' => '2026-10-20 15:30']);
    $otro = Turno::factory()->for($paciente)->create(['fecha_hora' => '2026-11-20 15:30']);

    // Uno resuelto: no tiene que aparecer.
    $otro->recordatorios()->sole()->completar();

    $this->actingAs($usuario)
        ->get(route('pacientes.turnos.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->has('recordatorios', 1)
            ->where('recordatorios.0.tipo', 'turno_proximo')
        );
});

/*
|--------------------------------------------------------------------------
| Edición y borrado
|--------------------------------------------------------------------------
*/

it('editar la hora mueve también el aviso', function (): void {
    [$usuario, $paciente] = fichaConTurnos();
    $turno = Turno::factory()->for($paciente)->create(['fecha_hora' => '2026-10-20 18:30']);

    $this->actingAs($usuario)
        ->put(route('turnos.update', $turno), [
            'fecha_hora' => '2026-10-25T09:00',
            'estado' => EstadoTurno::Programado->value,
        ])
        ->assertSessionHas('exito');

    // 09:00 de Buenos Aires = 12:00 UTC; el aviso, 24 horas antes.
    expect(Recordatorio::sole()->fecha->format('Y-m-d H:i'))->toBe('2026-10-24 12:00');
});

it('cancelar el turno desde la pantalla le saca el aviso', function (): void {
    [$usuario, $paciente] = fichaConTurnos();
    $turno = Turno::factory()->for($paciente)->create(['fecha_hora' => '2026-10-20 18:30']);
    expect(Recordatorio::count())->toBe(1);

    $this->actingAs($usuario)->put(route('turnos.update', $turno), [
        'fecha_hora' => '2026-10-20T15:30',
        'estado' => EstadoTurno::Cancelado->value,
    ]);

    expect(Recordatorio::count())->toBe(0);
});

it('borrar el turno se lleva su aviso', function (): void {
    [$usuario, $paciente] = fichaConTurnos();
    $turno = Turno::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->delete(route('turnos.destroy', $turno))
        ->assertSessionHas('exito');

    expect(Turno::count())->toBe(0)
        ->and(Recordatorio::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Los recordatorios: solo se marcan hechos
|--------------------------------------------------------------------------
*/

it('marcar un aviso como hecho lo cierra, y se puede reabrir', function (): void {
    [$usuario, $paciente] = fichaConTurnos();
    $turno = Turno::factory()->for($paciente)->create();
    $aviso = $turno->recordatorios()->sole();

    $this->actingAs($usuario)
        ->put(route('recordatorios.update', $aviso), ['completado' => '1'])
        ->assertSessionHas('exito');

    expect($aviso->fresh()->estado)->toBe(EstadoRecordatorio::Completado)
        ->and($aviso->fresh()->fecha_completado)->not->toBeNull();

    $this->actingAs($usuario)->put(route('recordatorios.update', $aviso), ['completado' => '0']);

    expect($aviso->fresh()->estado)->toBe(EstadoRecordatorio::Pendiente)
        ->and($aviso->fresh()->fecha_completado)->toBeNull();
});

it('reabrir uno que ya se avisó lo deja en ENVIADO, no en pendiente', function (): void {
    /*
     * El mail ya salió: eso es un hecho del pasado. Volverlo a "pendiente"
     * haría que el comando horario lo mandara de nuevo, que es justo lo que
     * `enviado_en` existe para evitar.
     */
    [$usuario, $paciente] = fichaConTurnos();
    $turno = Turno::factory()->for($paciente)->create();
    $aviso = $turno->recordatorios()->sole();
    $aviso->forceFill(['enviado_en' => now(), 'estado' => EstadoRecordatorio::Enviado])->save();
    $aviso->completar();

    $this->actingAs($usuario)->put(route('recordatorios.update', $aviso), ['completado' => '0']);

    expect($aviso->fresh()->estado)->toBe(EstadoRecordatorio::Enviado)
        ->and($aviso->fresh()->enviado_en)->not->toBeNull();
});

it('no hay ninguna ruta para crear ni para borrar un recordatorio', function (): void {
    // La ausencia ES el diseño: los recordatorios los genera un observer.
    $nombres = collect(app('router')->getRoutes())
        ->map(fn ($ruta) => $ruta->getName())
        ->filter(fn (?string $n): bool => $n !== null && str_starts_with($n, 'recordatorios.'))
        ->values()
        ->all();

    expect($nombres)->toBe(['recordatorios.update']);
});

/*
|--------------------------------------------------------------------------
| Autorización
|--------------------------------------------------------------------------
*/

it('alguien ajeno a la ficha no ve la agenda ni la toca', function (): void {
    [, $paciente] = fichaConTurnos();
    $turno = Turno::factory()->for($paciente)->create();
    $ajeno = User::factory()->create();

    $this->actingAs($ajeno)
        ->get(route('pacientes.turnos.index', $paciente))
        ->assertForbidden();

    $this->actingAs($ajeno)
        ->delete(route('turnos.destroy', $turno))
        ->assertForbidden();
});

it('un lector ve la agenda pero no agenda ni marca avisos', function (): void {
    [, $paciente] = fichaConTurnos();
    $turno = Turno::factory()->for($paciente)->create();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->get(route('pacientes.turnos.index', $paciente))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('paciente.puedeEditar', false));

    $this->actingAs($lector)
        ->post(route('pacientes.turnos.store', $paciente), [
            'fecha_hora' => '2026-10-20T15:30',
            'estado' => EstadoTurno::Programado->value,
        ])
        ->assertForbidden();

    $this->actingAs($lector)
        ->put(route('recordatorios.update', $turno->recordatorios()->sole()), ['completado' => '1'])
        ->assertForbidden();
});

it('un cuidador sí puede agendar', function (): void {
    [, $paciente] = fichaConTurnos();
    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->actingAs($cuidador)
        ->post(route('pacientes.turnos.store', $paciente), [
            'fecha_hora' => '2026-10-20T15:30',
            'estado' => EstadoTurno::Programado->value,
        ])
        ->assertSessionHas('exito');
});
