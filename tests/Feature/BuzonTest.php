<?php

declare(strict_types=1);

use App\Enums\MotivoDeRevision;
use App\Enums\ResultadoDeMail;
use App\Enums\RolPaciente;
use App\Enums\TipoAdjunto;
use App\Enums\TipoDocumentoPorMail;
use App\Mail\RespuestaDelBuzon;
use App\Models\DocumentoPorRevisar;
use App\Models\MailRecibido;
use App\Models\Paciente;
use App\Models\User;
use App\Services\LectorDeCasilla;
use App\Services\ProcesadorDeBuzon;
use App\Services\RegistradorDeDocumento;
use App\Support\AdjuntoDeCasilla;
use App\Support\MensajeDeCasilla;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
 * El procesador entero, sin red: el lector se reemplaza por un doble que
 * devuelve los mails que arma cada test.
 */

beforeEach(function (): void {
    Storage::fake('local');
    Mail::fake();
});

const PDF_DEL_BUZON = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

function cabecerasAutenticas(string $remitente): string
{
    $dominio = substr($remitente, strpos($remitente, '@') + 1);

    return "Authentication-Results: mx.hostinger.com;\r\n\tspf=pass smtp.mailfrom={$remitente};\r\n"
        ."\tdkim=pass header.d={$dominio} header.s=x;\r\n\tdmarc=pass (p=NONE) header.from={$dominio}\r\n"
        ."From: {$remitente}\r\n\r\n";
}

/**
 * @param  list<AdjuntoDeCasilla>|null  $adjuntos
 */
function mailAlBuzon(
    string $remitente,
    ?string $asunto,
    ?array $adjuntos = null,
    ?string $cabeceras = null,
    ?string $id = null,
): MensajeDeCasilla {
    return new MensajeDeCasilla(
        identificador: $id ?? '<'.uniqid('', true).'@gmail.com>',
        remitente: $remitente,
        asunto: $asunto,
        fecha: CarbonImmutable::parse('2026-10-15 15:00:00', 'UTC'),
        adjuntos: $adjuntos ?? [new AdjuntoDeCasilla('receta.pdf', PDF_DEL_BUZON)],
        cabeceras: $cabeceras ?? cabecerasAutenticas($remitente),
        uid: random_int(1, 1_000_000),
    );
}

/**
 * Pone el doble del lector y devuelve los UID que se le pidió mover.
 *
 * @param  list<MensajeDeCasilla>  $mensajes
 */
function buzonCon(array $mensajes): ArrayObject
{
    $movidos = new ArrayObject;

    $doble = Mockery::mock(LectorDeCasilla::class);
    $doble->shouldReceive('mensajes')->andReturn($mensajes);
    $doble->shouldReceive('moverAProcesados')->andReturnUsing(function (array $uids) use ($movidos): void {
        foreach ($uids as $uid) {
            $movidos[] = $uid;
        }
    });

    app()->instance(LectorDeCasilla::class, $doble);

    return $movidos;
}

function procesarBuzon(): array
{
    return app(ProcesadorDeBuzon::class)->procesar();
}

/**
 * @return array{0: User, 1: Paciente}
 */
function cuentaConUnaFicha(string $email = 'hija@gmail.com', string $nombre = 'Ana Pérez'): array
{
    $cuenta = User::factory()->create(['email' => $email]);
    $paciente = Paciente::factory()->for($cuenta, 'usuario')->create(['nombre' => $nombre]);

    return [$cuenta, $paciente];
}

/*
|--------------------------------------------------------------------------
| Directo a la ficha
|--------------------------------------------------------------------------
*/

it('guarda una receta en la ficha de la cuenta que la manda, con su PDF', function (): void {
    [, $paciente] = cuentaConUnaFicha();
    $movidos = buzonCon([$mail = mailAlBuzon('hija@gmail.com', 'Receta Enalapril 10 mg')]);

    expect(procesarBuzon())->toBe(['guardado' => 1]);

    $receta = $paciente->recetasMedicas()->sole();

    expect($receta->detalle)->toBe('Enalapril 10 mg')
        ->and($receta->fecha->toDateString())->toBe('2026-10-15')
        ->and($receta->adjuntos()->sole()->tipo)->toBe(TipoAdjunto::Receta)
        ->and($receta->adjuntos()->sole()->descripcion)->toBe('Llegó por mail el 15/10/2026')
        ->and($movidos->getArrayCopy())->toBe([$mail->uid]);

    Mail::assertSent(RespuestaDelBuzon::class, fn (RespuestaDelBuzon $r) => $r->hasTo('hija@gmail.com')
        && $r->resultado === ResultadoDeMail::Guardado
        && $r->paciente === 'Ana Pérez');
});

it('guarda una orden y un estudio en su lugar', function (): void {
    [, $paciente] = cuentaConUnaFicha();
    buzonCon([
        mailAlBuzon('hija@gmail.com', 'Orden Ecografía abdominal'),
        mailAlBuzon('hija@gmail.com', 'Estudio Laboratorio'),
    ]);

    procesarBuzon();

    expect($paciente->ordenesEstudio()->sole()->estudio_solicitado)->toBe('Ecografía abdominal')
        ->and($paciente->ordenesEstudio()->sole()->adjuntos()->sole()->tipo)->toBe(TipoAdjunto::OrdenEstudio)
        ->and($paciente->estudios()->sole()->tipo)->toBe('Laboratorio')
        ->and($paciente->estudios()->sole()->adjuntos()->sole()->tipo)->toBe(TipoAdjunto::InformeEstudio);
});

it('reconoce el correo cargado en la ficha del paciente', function (): void {
    $duenia = User::factory()->create();
    $paciente = Paciente::factory()->for($duenia, 'usuario')->create(['email' => 'Mama@Gmail.com']);
    buzonCon([mailAlBuzon('mama@gmail.com', 'Receta')]);

    procesarBuzon();

    expect($paciente->recetasMedicas()->count())->toBe(1);
});

it('la fecha es el día del mail en la zona de la cuenta, no en UTC', function (): void {
    [, $paciente] = cuentaConUnaFicha();
    // 01:30 UTC del 16 son las 22:30 del 15 en Argentina.
    $mail = new MensajeDeCasilla(
        identificador: '<tarde@gmail.com>',
        remitente: 'hija@gmail.com',
        asunto: 'Receta',
        fecha: CarbonImmutable::parse('2026-10-16 01:30:00', 'UTC'),
        adjuntos: [new AdjuntoDeCasilla('r.pdf', PDF_DEL_BUZON)],
        cabeceras: cabecerasAutenticas('hija@gmail.com'),
        uid: 1,
    );
    buzonCon([$mail]);

    procesarBuzon();

    expect($paciente->recetasMedicas()->sole()->fecha->toDateString())->toBe('2026-10-15');
});

it('con varias fichas, el nombre en el asunto elige la ficha', function (): void {
    [$cuenta, $ana] = cuentaConUnaFicha('hija@gmail.com', 'Ana Pérez');
    $mama = Paciente::factory()->for($cuenta, 'usuario')->create(['nombre' => 'Marta Gómez']);
    buzonCon([mailAlBuzon('hija@gmail.com', 'Receta Marta Losartán')]);

    procesarBuzon();

    expect($mama->recetasMedicas()->sole()->detalle)->toBe('Losartán')
        ->and($ana->recetasMedicas()->count())->toBe(0);
});

it('un cuidador también puede mandar a la ficha que cuida', function (): void {
    $duenia = User::factory()->create();
    $paciente = Paciente::factory()->for($duenia, 'usuario')->create();
    $cuidador = User::factory()->create(['email' => 'cuidador@gmail.com']);
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);
    buzonCon([mailAlBuzon('cuidador@gmail.com', 'Receta')]);

    procesarBuzon();

    expect($paciente->recetasMedicas()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Por revisar
|--------------------------------------------------------------------------
*/

it('sin tipo en el asunto, queda por revisar en la ficha y contesta', function (): void {
    [$cuenta, $paciente] = cuentaConUnaFicha();
    buzonCon([mailAlBuzon('hija@gmail.com', 'Te mando esto')]);

    expect(procesarBuzon())->toBe(['por_revisar' => 1]);

    $documento = DocumentoPorRevisar::sole();

    expect($documento->paciente_id)->toBe($paciente->id)
        ->and($documento->usuario_id)->toBe($cuenta->id)
        ->and($documento->motivo)->toBe(MotivoDeRevision::SinTipo)
        ->and($documento->adjuntos()->count())->toBe(1)
        ->and($paciente->recetasMedicas()->count())->toBe(0);

    Mail::assertSent(RespuestaDelBuzon::class, fn (RespuestaDelBuzon $r) => $r->resultado === ResultadoDeMail::PorRevisar);
});

it('con varias fichas y sin nombre en el asunto, queda para la cuenta', function (): void {
    [$cuenta] = cuentaConUnaFicha('hija@gmail.com', 'Ana Pérez');
    Paciente::factory()->for($cuenta, 'usuario')->create(['nombre' => 'Marta Gómez']);
    buzonCon([mailAlBuzon('hija@gmail.com', 'Receta Enalapril')]);

    procesarBuzon();

    $documento = DocumentoPorRevisar::sole();

    expect($documento->paciente_id)->toBeNull()
        ->and($documento->usuario_id)->toBe($cuenta->id)
        ->and($documento->motivo)->toBe(MotivoDeRevision::PacienteAmbiguo)
        ->and($documento->tipo_sugerido)->toBe(TipoDocumentoPorMail::Receta);
});

it('⚠️ si el servidor no confirma que el mail sea auténtico, no entra directo', function (): void {
    [, $paciente] = cuentaConUnaFicha();
    // Un mail que DICE venir de hija@gmail.com, sin verificación.
    buzonCon([mailAlBuzon('hija@gmail.com', 'Receta', cabeceras: "Authentication-Results: mx; spf=fail; dmarc=fail header.from=gmail.com\r\n\r\n")]);

    procesarBuzon();

    expect($paciente->recetasMedicas()->count())->toBe(0)
        ->and(DocumentoPorRevisar::sole()->motivo)->toBe(MotivoDeRevision::SinVerificar);
});

it('⚠️ un "pass" escrito por el remitente más abajo no cuenta', function (): void {
    [, $paciente] = cuentaConUnaFicha();
    $falsas = "Authentication-Results: mx.hostinger.com; dmarc=fail header.from=gmail.com\r\n"
        ."Authentication-Results: mx.hostinger.com; dmarc=pass header.from=gmail.com\r\n\r\n";
    buzonCon([mailAlBuzon('hija@gmail.com', 'Receta', cabeceras: $falsas)]);

    procesarBuzon();

    expect($paciente->recetasMedicas()->count())->toBe(0);
});

it('un paciente cargado en dos familias deja uno por revisar para cada ficha', function (): void {
    $a = Paciente::factory()->create(['email' => 'abuela@gmail.com']);
    $b = Paciente::factory()->create(['email' => 'abuela@gmail.com']);
    buzonCon([mailAlBuzon('abuela@gmail.com', 'Receta')]);

    procesarBuzon();

    expect(DocumentoPorRevisar::pluck('paciente_id')->sort()->values()->all())->toBe([$a->id, $b->id]);
});

/*
|--------------------------------------------------------------------------
| Lo que se descarta
|--------------------------------------------------------------------------
*/

it('⚠️ un remitente desconocido no deja nada y no recibe respuesta', function (): void {
    cuentaConUnaFicha();
    buzonCon([mailAlBuzon('extranio@gmail.com', 'Receta')]);

    expect(procesarBuzon())->toBe(['desconocido' => 1]);

    $registro = MailRecibido::sole();

    expect(DocumentoPorRevisar::count())->toBe(0)
        ->and(DB::table('recetas_medicas')->count())->toBe(0)
        // De un desconocido no se guarda ni la dirección ni el asunto.
        ->and($registro->remitente)->toBeNull()
        ->and($registro->asunto)->toBeNull();

    Mail::assertNothingSent();
});

it('una cuenta con el mail sin verificar no cuenta', function (): void {
    $cuenta = User::factory()->unverified()->create(['email' => 'sinverificar@gmail.com']);
    Paciente::factory()->for($cuenta, 'usuario')->create();
    buzonCon([mailAlBuzon('sinverificar@gmail.com', 'Receta')]);

    expect(procesarBuzon())->toBe(['desconocido' => 1]);
});

it('un lector no puede mandar a la ficha', function (): void {
    $paciente = Paciente::factory()->create();
    $lector = User::factory()->create(['email' => 'lector@gmail.com']);
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);
    buzonCon([mailAlBuzon('lector@gmail.com', 'Receta')]);

    procesarBuzon();

    expect($paciente->recetasMedicas()->count())->toBe(0);
});

it('a un mail automático no se le contesta', function (): void {
    cuentaConUnaFicha();
    buzonCon([mailAlBuzon('hija@gmail.com', 'Receta', cabeceras: "Auto-Submitted: auto-replied\r\n\r\n")]);

    expect(procesarBuzon())->toBe(['automatico' => 1]);
    Mail::assertNothingSent();
});

it('sin archivos que sirvan, no guarda nada y lo contesta', function (): void {
    [, $paciente] = cuentaConUnaFicha();
    buzonCon([mailAlBuzon('hija@gmail.com', 'Receta', adjuntos: [new AdjuntoDeCasilla('pagina.html', '<html><script>x</script></html>')])]);

    expect(procesarBuzon())->toBe(['sin_archivos' => 1])
        ->and($paciente->recetasMedicas()->count())->toBe(0);

    Mail::assertSent(RespuestaDelBuzon::class, fn (RespuestaDelBuzon $r) => $r->resultado === ResultadoDeMail::SinArchivos);
});

it('un mail ya procesado no se procesa dos veces', function (): void {
    [, $paciente] = cuentaConUnaFicha();
    $mail = mailAlBuzon('hija@gmail.com', 'Receta', id: '<unico@gmail.com>');

    buzonCon([$mail]);
    procesarBuzon();
    $movidos = buzonCon([$mail]);
    procesarBuzon();

    expect($paciente->recetasMedicas()->count())->toBe(1)
        // Igual se lo saca del INBOX.
        ->and($movidos->getArrayCopy())->toBe([$mail->uid]);
});

it('respeta el tope de mails por remitente por día', function (): void {
    config(['misalud.buzon.tope_por_remitente_por_dia' => 2]);
    [, $paciente] = cuentaConUnaFicha();
    buzonCon([
        mailAlBuzon('hija@gmail.com', 'Receta uno'),
        mailAlBuzon('hija@gmail.com', 'Receta dos'),
        mailAlBuzon('hija@gmail.com', 'Receta tres'),
    ]);

    expect(procesarBuzon())->toBe(['guardado' => 2, 'excedido' => 1])
        ->and($paciente->recetasMedicas()->count())->toBe(2);
});

it('un mail roto no frena a los demás y queda en el INBOX', function (): void {
    [, $paciente] = cuentaConUnaFicha();
    $roto = mailAlBuzon('hija@gmail.com', 'Receta rota');
    $bien = mailAlBuzon('hija@gmail.com', 'Receta buena');

    // El primero revienta al guardar el archivo (un adjunto vacío lo rechaza el servicio
    // recién al escribir: lo forzamos con un doble del adjuntador).
    $movidos = buzonCon([$roto, $bien]);
    $original = app(RegistradorDeDocumento::class);
    $registrador = Mockery::mock(RegistradorDeDocumento::class);
    $registrador->shouldReceive('crear')->andReturnUsing(function (...$args) use ($original) {
        if ($args[2] === 'rota') {
            throw new RuntimeException('falló');
        }

        return $original->crear(...$args);
    });
    app()->instance(RegistradorDeDocumento::class, $registrador);

    expect(procesarBuzon())->toBe(['error' => 1, 'guardado' => 1])
        ->and($paciente->recetasMedicas()->sole()->detalle)->toBe('buena')
        ->and($movidos->getArrayCopy())->toBe([$bien->uid])
        // Si se cae la transacción, no queda ningún archivo sin dueño.
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('la respuesta es texto plano, sin nombres en el asunto ni escapes de HTML', function (): void {
    $respuesta = new RespuestaDelBuzon(ResultadoDeMail::Guardado, TipoDocumentoPorMail::Receta, "María D'Angelo");

    $texto = $respuesta->render();

    expect($respuesta->envelope()->subject)->toBe('MiSalud: recibimos tu mail')
        ->and($texto)->toContain("María D'Angelo")
        ->and($texto)->not->toContain('&#039;')
        ->and($texto)->not->toContain('<html');
});

/*
|--------------------------------------------------------------------------
| Adónde se mueve lo procesado
|--------------------------------------------------------------------------
|
| Medido contra el buzón real de Hostinger: las carpetas cuelgan del INBOX con
| `.` (INBOX.Sent, INBOX.Trash) y crear `Procesados` en la raíz da error.
*/

it('crea Procesados adentro del INBOX cuando no existe', function (): void {
    expect(LectorDeCasilla::rutaDeProcesados('Procesados', 'INBOX', '.', ['INBOX', 'INBOX.Sent', 'INBOX.Trash']))
        ->toBe('INBOX.Procesados');
});

it('usa la de la raíz si el servidor ya la tiene ahí', function (): void {
    expect(LectorDeCasilla::rutaDeProcesados('Procesados', 'INBOX', '/', ['INBOX', 'Procesados']))
        ->toBe('Procesados');
});

it('respeta el delimitador del servidor', function (): void {
    expect(LectorDeCasilla::rutaDeProcesados('Procesados', 'INBOX', '/', ['INBOX']))
        ->toBe('INBOX/Procesados');
});
