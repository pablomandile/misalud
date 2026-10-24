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
| Pedido del usuario: con «Una vez por año» se carga la fecha de la próxima
| aplicación, y sale un mail un mes antes y otro una semana antes.
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
            'proxima_aplicacion' => '2026-12-15',
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

it('guarda la fecha y genera los dos avisos: un mes antes y una semana antes', function (): void {
    [$usuario, $paciente, $medicamento] = fichaAnual();

    $tratamiento = cargarAnual($this, $usuario, $paciente, $medicamento);

    expect($tratamiento->proxima_aplicacion->format('Y-m-d'))->toBe('2026-12-15')
        ->and(avisosDe($tratamiento))->toBe([
            TipoRecordatorio::AplicacionAnualMes->value => '2026-11-15 00:00',
            TipoRecordatorio::AplicacionAnualSemana->value => '2026-12-08 00:00',
        ]);
});

it('con otra toma no guarda la fecha ni avisa, aunque el campo oculto viaje', function (): void {
    [$usuario, $paciente, $medicamento] = fichaAnual();

    $tratamiento = cargarAnual($this, $usuario, $paciente, $medicamento, ['periodicidad' => 'mensual']);

    expect($tratamiento->proxima_aplicacion)->toBeNull()
        ->and(avisosDe($tratamiento))->toBe([]);
});

it('sin fecha cargada no hay avisos', function (): void {
    [$usuario, $paciente, $medicamento] = fichaAnual();

    $tratamiento = cargarAnual($this, $usuario, $paciente, $medicamento, ['proxima_aplicacion' => '']);

    expect(avisosDe($tratamiento))->toBe([]);
});

it('cambiar la fecha mueve los dos avisos; pasar a otra toma o suspenderlo los saca', function (): void {
    [$usuario, $paciente, $medicamento] = fichaAnual();
    $tratamiento = cargarAnual($this, $usuario, $paciente, $medicamento);

    $tratamiento->update(['proxima_aplicacion' => '2027-03-01']);
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
