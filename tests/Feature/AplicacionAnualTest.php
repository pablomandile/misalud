<?php

declare(strict_types=1);

use App\Enums\PeriodicidadToma;
use App\Enums\TipoRecordatorio;
use App\Mail\AvisoDeRecordatorio;
use App\Models\Medicamento;
use App\Models\Paciente;
use App\Models\Recordatorio;
use App\Models\Tratamiento;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Medicamento de "una vez por año": la próxima aplicación y sus dos avisos
|--------------------------------------------------------------------------
|
| Pedido del usuario: con «Una vez por año» se carga el día y el mes de la
| aplicación; la próxima se calcula sola, y sale un mail un mes antes y otro
| una semana antes.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-10 12:00:00');
    Mail::fake();
});

/**
 * @return array{0: User, 1: Paciente, 2: Medicamento}
 */
function fichaAnual(): array
{
    $usuario = User::factory()->create([
        'zona_horaria' => 'America/Argentina/Buenos_Aires',
        'email_verified_at' => now(),
    ]);
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Marta']);

    return [$usuario, $paciente, Medicamento::factory()->for($usuario, 'usuario')->create()];
}

function cargarAnual(object $test, User $usuario, Paciente $paciente, Medicamento $medicamento, array $datos = []): Tratamiento
{
    $test->actingAs($usuario)
        ->post(route('pacientes.tratamientos.store', $paciente), [
            'medicamento_id' => $medicamento->id,
            'dosis' => '1 aplicación',
            'periodicidad' => 'anual',
            'aplicacion_dia' => 15,
            'aplicacion_mes' => 12,
            'inicio' => '2026-01-01',
            ...$datos,
        ])
        ->assertSessionHasNoErrors();

    return Tratamiento::sole();
}

/**
 * @return array<string, string>
 */
function avisosDe(Tratamiento $tratamiento): array
{
    return $tratamiento->recordatorios()->get()
        ->mapWithKeys(fn (Recordatorio $r): array => [$r->tipo->value => $r->fecha->format('Y-m-d H:i')])
        ->sort()
        ->all();
}

it('guarda día y mes, calcula la próxima y genera los dos avisos', function (): void {
    [$usuario, $paciente, $medicamento] = fichaAnual();

    $tratamiento = cargarAnual($this, $usuario, $paciente, $medicamento);

    expect([$tratamiento->aplicacion_dia, $tratamiento->aplicacion_mes])->toBe([15, 12])
        ->and($tratamiento->proximaAplicacion(now()->toImmutable())?->format('Y-m-d'))->toBe('2026-12-15')
        ->and($tratamiento->aplicacionVisible())->toBe('Cada año el 15 de diciembre')
        ->and(avisosDe($tratamiento))->toBe([
            TipoRecordatorio::AplicacionAnualMes->value => '2026-11-15 00:00',
            TipoRecordatorio::AplicacionAnualSemana->value => '2026-12-08 00:00',
        ]);
});

it('con otra toma no guarda día ni mes ni avisa, aunque los campos ocultos viajen', function (): void {
    [$usuario, $paciente, $medicamento] = fichaAnual();

    $tratamiento = cargarAnual($this, $usuario, $paciente, $medicamento, ['periodicidad' => 'mensual']);

    expect($tratamiento->aplicacion_dia)->toBeNull()
        ->and($tratamiento->aplicacion_mes)->toBeNull()
        ->and(avisosDe($tratamiento))->toBe([]);
});

it('sin día y mes no hay avisos', function (): void {
    [$usuario, $paciente, $medicamento] = fichaAnual();

    $tratamiento = cargarAnual($this, $usuario, $paciente, $medicamento, ['aplicacion_dia' => '', 'aplicacion_mes' => '']);

    expect(avisosDe($tratamiento))->toBe([]);
});

it('cambiar la fecha mueve los dos avisos; pasar a otra toma o suspenderlo los saca', function (): void {
    [$usuario, $paciente, $medicamento] = fichaAnual();
    $tratamiento = cargarAnual($this, $usuario, $paciente, $medicamento);

    $tratamiento->update(['aplicacion_dia' => 1, 'aplicacion_mes' => 3]);
    expect(avisosDe($tratamiento))->toBe([
        TipoRecordatorio::AplicacionAnualMes->value => '2027-01-30 00:00',
        TipoRecordatorio::AplicacionAnualSemana->value => '2027-02-22 00:00',
    ]);

    $tratamiento->update(['activo' => false]);
    expect(avisosDe($tratamiento))->toBe([]);

    $tratamiento->update(['activo' => true, 'periodicidad' => PeriodicidadToma::Diaria]);
    expect(avisosDe($tratamiento))->toBe([]);
});

it('borrar el medicamento se lleva sus avisos', function (): void {
    [$usuario, $paciente, $medicamento] = fichaAnual();
    $tratamiento = cargarAnual($this, $usuario, $paciente, $medicamento);

    $tratamiento->delete();

    expect(Recordatorio::count())->toBe(0);
});

it('a un mes de la fecha sale el primer mail, sin decir qué medicamento y con el día correcto', function (): void {
    [$usuario, $paciente, $medicamento] = fichaAnual();
    cargarAnual($this, $usuario, $paciente, $medicamento);

    Carbon::setTestNow('2026-11-15 00:30:00');
    $this->artisan('misalud:enviar-recordatorios')->assertSuccessful();

    Mail::assertSent(AvisoDeRecordatorio::class, 1);
    Mail::assertSent(AvisoDeRecordatorio::class, function (AvisoDeRecordatorio $mail) use ($usuario, $medicamento): bool {
        $texto = $mail->render();

        return $mail->hasTo($usuario->email)
            && $mail->hasSubject('MiSalud: en un mes toca la aplicación de un medicamento')
            && str_contains($texto, 'Próxima aplicación de un medicamento de Marta: el 15/12/2026')
            && ! str_contains($texto, $medicamento->nombre_comercial);
    });
});

it('a una semana sale el segundo, y no se repite el primero', function (): void {
    [$usuario, $paciente, $medicamento] = fichaAnual();
    cargarAnual($this, $usuario, $paciente, $medicamento);

    Carbon::setTestNow('2026-11-15 00:30:00');
    $this->artisan('misalud:enviar-recordatorios')->assertSuccessful();

    Carbon::setTestNow('2026-12-08 00:30:00');
    $this->artisan('misalud:enviar-recordatorios')->assertSuccessful();

    Mail::assertSent(AvisoDeRecordatorio::class, 2);
    Mail::assertSent(
        AvisoDeRecordatorio::class,
        fn (AvisoDeRecordatorio $mail): bool => $mail->hasSubject('MiSalud: en una semana toca la aplicación de un medicamento'),
    );
});

it('si este año ya pasó, la próxima es la del año que viene', function (): void {
    [$usuario, $paciente, $medicamento] = fichaAnual();

    $tratamiento = cargarAnual($this, $usuario, $paciente, $medicamento, ['aplicacion_dia' => 1, 'aplicacion_mes' => 5]);

    expect($tratamiento->proximaAplicacion(now()->toImmutable())?->format('Y-m-d'))->toBe('2027-05-01')
        ->and($tratamiento->proximaAplicacion(now()->setDate(2026, 5, 1)->toImmutable())?->format('Y-m-d'))
        ->toBe('2026-05-01');
});

it('un 29 de febrero cae el 28 los años que no lo tienen', function (): void {
    [$usuario, $paciente, $medicamento] = fichaAnual();

    $tratamiento = cargarAnual($this, $usuario, $paciente, $medicamento, ['aplicacion_dia' => 29, 'aplicacion_mes' => 2]);

    expect($tratamiento->proximaAplicacion(now()->toImmutable())?->format('Y-m-d'))->toBe('2027-02-28')
        ->and($tratamiento->proximaAplicacion(now()->setDate(2027, 10, 1)->toImmutable())?->format('Y-m-d'))
        ->toBe('2028-02-29');
});

it('rechaza un día que el mes no tiene, y el día sin el mes', function (): void {
    [$usuario, $paciente, $medicamento] = fichaAnual();

    $datos = [
        'medicamento_id' => $medicamento->id,
        'dosis' => '1',
        'periodicidad' => 'anual',
        'inicio' => '2026-01-01',
    ];

    $this->actingAs($usuario)
        ->post(route('pacientes.tratamientos.store', $paciente), [...$datos, 'aplicacion_dia' => 31, 'aplicacion_mes' => 4])
        ->assertSessionHasErrors(['aplicacion_dia' => 'Ese mes no tiene ese día.']);

    $this->actingAs($usuario)
        ->post(route('pacientes.tratamientos.store', $paciente), [...$datos, 'aplicacion_dia' => 10])
        ->assertSessionHasErrors('aplicacion_mes');
});

it('un aviso que ya tendría que haber salido no se crea', function (): void {
    [$usuario, $paciente, $medicamento] = fichaAnual();

    // Diez días antes: el de un mes ya pasó, el de una semana todavía no.
    $tratamiento = cargarAnual($this, $usuario, $paciente, $medicamento, ['aplicacion_dia' => 20, 'aplicacion_mes' => 10]);

    expect(avisosDe($tratamiento))->toBe([
        TipoRecordatorio::AplicacionAnualSemana->value => '2026-10-13 00:00',
    ]);
});

it('el comando diario conserva los avisos del año hasta la fecha y después los pasa al siguiente', function (): void {
    [$usuario, $paciente, $medicamento] = fichaAnual();
    $tratamiento = cargarAnual($this, $usuario, $paciente, $medicamento);

    Carbon::setTestNow('2026-11-15 00:30:00');
    $this->artisan('misalud:enviar-recordatorios')->assertSuccessful();

    // Ya salió el de un mes: el comando no lo borra.
    Carbon::setTestNow('2026-12-10 03:00:00');
    $this->artisan('misalud:renovar-aplicaciones-anuales')->assertSuccessful();
    expect(avisosDe($tratamiento))->toBe([
        TipoRecordatorio::AplicacionAnualMes->value => '2026-11-15 00:00',
        TipoRecordatorio::AplicacionAnualSemana->value => '2026-12-08 00:00',
    ]);

    // Pasada la aplicación, los dos van al año siguiente y vuelven a pendiente.
    Carbon::setTestNow('2026-12-16 03:00:00');
    $this->artisan('misalud:renovar-aplicaciones-anuales')->assertSuccessful();
    $this->artisan('misalud:renovar-aplicaciones-anuales')->assertSuccessful();

    expect(avisosDe($tratamiento))->toBe([
        TipoRecordatorio::AplicacionAnualMes->value => '2027-11-15 00:00',
        TipoRecordatorio::AplicacionAnualSemana->value => '2027-12-08 00:00',
    ])->and($tratamiento->recordatorios()->whereNotNull('enviado_en')->count())->toBe(0);
});
