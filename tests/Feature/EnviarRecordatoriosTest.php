<?php

declare(strict_types=1);

use App\Enums\EstadoRecordatorio;
use App\Enums\RolPaciente;
use App\Mail\AvisoDeRecordatorio;
use App\Models\Paciente;
use App\Models\Recordatorio;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-10 12:00:00');
    Mail::fake();
});

/**
 * Un turno con su aviso ya generado por el observer, en la fecha que se pida.
 *
 * Se crea el turno y se deja trabajar al observer -eso prueba el camino real-;
 * ver por qué `Recordatorio` no tiene factory en su propio comentario.
 *
 * @return array{0: User, 1: Paciente, 2: Recordatorio}
 */
function avisoDeTurno(string $fechaHora, ?User $usuario = null): array
{
    $usuario ??= User::factory()->create([
        'zona_horaria' => 'America/Argentina/Buenos_Aires',
        'email_verified_at' => now(),
    ]);
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $turno = Turno::factory()->for($paciente)->create(['fecha_hora' => $fechaHora]);

    return [$usuario, $paciente, $turno->recordatorios()->sole()];
}

/*
|--------------------------------------------------------------------------
| Qué se manda y qué no
|--------------------------------------------------------------------------
*/

it('manda el aviso cuando ya llegó su hora', function (): void {
    // Turno mañana a esta hora: el aviso vencía justo ahora.
    [$usuario, , $aviso] = avisoDeTurno('2026-10-11 12:00:00');

    $this->artisan('misalud:enviar-recordatorios')->assertSuccessful();

    Mail::assertSent(AvisoDeRecordatorio::class, 1);
    Mail::assertSent(
        AvisoDeRecordatorio::class,
        fn (AvisoDeRecordatorio $mail): bool => $mail->hasTo($usuario->email),
    );

    expect($aviso->fresh()->estado)->toBe(EstadoRecordatorio::Enviado)
        ->and($aviso->fresh()->enviado_en)->not->toBeNull();
});

it('NO manda uno cuya hora todavía no llegó', function (): void {
    // Turno dentro de diez días: falta mucho para el aviso.
    avisoDeTurno('2026-10-20 12:00:00');

    $this->artisan('misalud:enviar-recordatorios')->assertSuccessful();

    Mail::assertNothingSent();
    expect(Recordatorio::sole()->estado)->toBe(EstadoRecordatorio::Pendiente);
});

it('⚠️ NO avisa de algo que ya pasó: lo marca VENCIDO', function (): void {
    /*
     * El caso del servidor caído una semana. Mandar "tenés un turno" por un
     * turno que ya fue es peor que no mandar nada. El corte no es una
     * constante: es "el evento ya pasó".
     */
    avisoDeTurno('2026-10-09 12:00:00');

    $this->artisan('misalud:enviar-recordatorios')->assertSuccessful();

    Mail::assertNothingSent();
    expect(Recordatorio::sole()->estado)->toBe(EstadoRecordatorio::Vencido)
        // Vencido no es borrado: la pantalla puede distinguir "no te
        // avisamos" de "te avisamos y no lo resolviste".
        ->and(Recordatorio::count())->toBe(1);
});

it('avisa de un turno que es en un rato, aunque el aviso venciera ayer', function (): void {
    /*
     * El borde de la ventana por el otro lado: el aviso venció hace 20 horas
     * pero el turno es dentro de 4. Todavía sirve avisar.
     */
    avisoDeTurno('2026-10-10 16:00:00');

    $this->artisan('misalud:enviar-recordatorios')->assertSuccessful();

    Mail::assertSent(AvisoDeRecordatorio::class, 1);
});

it('no vuelve a mandar el mismo aviso en la corrida siguiente', function (): void {
    // Es lo que hace que correrlo cada hora no sea un problema.
    avisoDeTurno('2026-10-11 12:00:00');

    $this->artisan('misalud:enviar-recordatorios');
    $this->artisan('misalud:enviar-recordatorios');
    $this->artisan('misalud:enviar-recordatorios');

    Mail::assertSent(AvisoDeRecordatorio::class, 1);
});

it('no manda nada si no hay nada que mandar', function (): void {
    $this->artisan('misalud:enviar-recordatorios')
        ->expectsOutputToContain('No había recordatorios para mandar.')
        ->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| A quién se le manda
|--------------------------------------------------------------------------
*/

it('manda un mail POR destinatario, no uno con varios To', function (): void {
    /*
     * Dos motivos que apuntan al mismo lado: cada uno lee la hora en su zona,
     * y varios `To` le muestran a cada uno la dirección de los demás.
     */
    [, $paciente] = avisoDeTurno('2026-10-11 12:00:00');
    $cuidador = User::factory()->create(['email_verified_at' => now()]);
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->artisan('misalud:enviar-recordatorios');

    Mail::assertSent(AvisoDeRecordatorio::class, 2);
    Mail::assertSent(
        AvisoDeRecordatorio::class,
        fn (AvisoDeRecordatorio $mail): bool => count($mail->to) === 1,
    );
});

it('a un LECTOR no se le manda: no puede hacer nada con el aviso', function (): void {
    [, $paciente] = avisoDeTurno('2026-10-11 12:00:00');
    $lector = User::factory()->create(['email_verified_at' => now()]);
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->artisan('misalud:enviar-recordatorios');

    Mail::assertSent(AvisoDeRecordatorio::class, 1);
    Mail::assertNotSent(
        AvisoDeRecordatorio::class,
        fn (AvisoDeRecordatorio $mail): bool => $mail->hasTo($lector->email),
    );
});

it('a un mail SIN verificar no se le manda, y el aviso queda pendiente', function (): void {
    /*
     * Un mail sin verificar es una dirección que nadie probó que sea suya
     * -puede ser un tipeo que apunta a un tercero-. Queda `Pendiente` para
     * reintentar, y si nunca se verifica vencerá solo.
     */
    $sinVerificar = User::factory()->create([
        'zona_horaria' => 'America/Argentina/Buenos_Aires',
        'email_verified_at' => null,
    ]);
    avisoDeTurno('2026-10-11 12:00:00', $sinVerificar);

    $this->artisan('misalud:enviar-recordatorios')
        ->expectsOutputToContain('1 sin destinatario verificado')
        ->assertSuccessful();

    Mail::assertNothingSent();
    expect(Recordatorio::sole()->estado)->toBe(EstadoRecordatorio::Pendiente);
});

/*
|--------------------------------------------------------------------------
| Qué dice el mail
|--------------------------------------------------------------------------
*/

it('el asunto NO lleva el nombre de nadie', function (): void {
    /*
     * El asunto aparece en la pantalla bloqueada del teléfono y en los
     * registros de cualquier servidor de correo por el que pase.
     */
    [, $paciente, $aviso] = avisoDeTurno('2026-10-11 12:00:00');
    $paciente->update(['nombre' => 'Rosa Confidencial']);

    $mail = new AvisoDeRecordatorio($aviso->fresh(), $paciente->usuario);

    expect($mail->envelope()->subject)->toBe('MiSalud: tenés un turno próximo')
        ->and($mail->envelope()->subject)->not->toContain('Confidencial');
});

it('el cuerpo dice CUÁNDO y el nombre, pero no el motivo', function (): void {
    /*
     * El mail sale sin cifrar y queda en el servidor de correo de quien lo
     * reciba. Dice cuándo, no qué: el motivo es el contenido clínico de
     * verdad y se queda detrás del login.
     */
    $usuario = User::factory()->create([
        'zona_horaria' => 'America/Argentina/Buenos_Aires',
        'email_verified_at' => now(),
    ]);
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Rosa Pérez']);
    $turno = Turno::factory()->for($paciente)->create([
        'fecha_hora' => '2026-10-11 15:00:00',
        'motivo' => 'Motivo Reservadisimo',
    ]);

    $cuerpo = (new AvisoDeRecordatorio($turno->recordatorios()->sole(), $usuario))
        ->render();

    expect($cuerpo)->toContain('Rosa Pérez')
        // 15:00 UTC son las 12:00 en Buenos Aires: la hora del EVENTO, en la
        // zona de ESTE destinatario.
        ->and($cuerpo)->toContain('11/10/2026 a las 12:00')
        ->and($cuerpo)->not->toContain('Reservadisimo');
});

it('cada destinatario ve la hora en SU zona', function (): void {
    // Es lo que obliga a un mail por persona y no uno con varios To.
    $porteno = User::factory()->create([
        'zona_horaria' => 'America/Argentina/Buenos_Aires',
        'email_verified_at' => now(),
    ]);
    $paciente = Paciente::factory()->for($porteno, 'usuario')->create();
    $madrileno = User::factory()->create([
        'zona_horaria' => 'Europe/Madrid',
        'email_verified_at' => now(),
    ]);
    $paciente->cuidadores()->attach($madrileno, ['rol' => RolPaciente::Cuidador->value]);

    $turno = Turno::factory()->for($paciente)->create(['fecha_hora' => '2026-10-11 15:00:00']);
    $aviso = $turno->recordatorios()->sole();

    // 15:00 UTC = 12:00 en Buenos Aires = 17:00 en Madrid.
    expect((new AvisoDeRecordatorio($aviso, $porteno))->render())->toContain('a las 12:00')
        ->and((new AvisoDeRecordatorio($aviso, $madrileno))->render())->toContain('a las 17:00');
});

/*
|--------------------------------------------------------------------------
| El ensayo en seco, y no encolar
|--------------------------------------------------------------------------
*/

it('--seco no manda nada ni cambia ningún estado', function (): void {
    avisoDeTurno('2026-10-11 12:00:00');

    $this->artisan('misalud:enviar-recordatorios --seco')
        ->expectsOutputToContain('[seco]')
        ->assertSuccessful();

    Mail::assertNothingSent();
    expect(Recordatorio::sole()->estado)->toBe(EstadoRecordatorio::Pendiente);
});

it('el mailable NO se encola', function (): void {
    /*
     * Lo dispara un comando del scheduler, que ya es asíncrono. Encolarlo
     * obligaría a un `queue:work`, y en hosting compartido un worker se cae
     * en silencio: el modo de falla sería quedarse sin avisos sin que nadie
     * se entere.
     */
    expect(new AvisoDeRecordatorio(Recordatorio::make(), User::factory()->make()))
        ->not->toBeInstanceOf(ShouldQueue::class);
});
