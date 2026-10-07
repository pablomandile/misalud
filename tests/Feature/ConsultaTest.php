<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Enums\TipoAdjunto;
use App\Models\Adjunto;
use App\Models\Consulta;
use App\Models\Enfermedad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Carbon::setTestNow('2026-10-01 15:00:00');
});

/**
 * @return array{0: User, 1: Paciente}
 */
function fichaConConsultas(): array
{
    $usuario = User::factory()->create(['zona_horaria' => 'America/Argentina/Buenos_Aires']);
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    return [$usuario, $paciente];
}

/** Un archivo de audio de verdad, de los fixtures, con el nombre que se quiera. */
function grabacion(string $fixture = 'prueba.m4a', ?string $nombre = null): UploadedFile
{
    $origen = base_path("tests/Fixtures/audio/{$fixture}");
    $copia = tempnam(sys_get_temp_dir(), 'audio');
    copy($origen, $copia);

    return new UploadedFile($copia, $nombre ?? $fixture, null, null, true);
}

/*
|--------------------------------------------------------------------------
| La consulta
|--------------------------------------------------------------------------
*/

it('carga una consulta y guarda la hora en UTC desde la zona de la cuenta', function (): void {
    [$usuario, $paciente] = fichaConConsultas();
    $medico = Medico::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.consultas.store', $paciente), [
            'fecha_hora' => '2026-09-30T10:00',
            'medico_id' => $medico->id,
            'motivo' => 'Control',
            'notas' => 'Dijo que siga con la dieta',
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    $consulta = Consulta::sole();

    // 10:00 en Buenos Aires son las 13:00 UTC.
    expect($consulta->fecha_hora->format('Y-m-d H:i'))->toBe('2026-09-30 13:00')
        ->and($consulta->paciente_id)->toBe($paciente->id)
        ->and($consulta->notas)->toBe('Dijo que siga con la dieta');

    $fila = DB::table('consultas')->first();
    expect($fila->notas)->not->toContain('dieta')
        ->and($fila->motivo)->not->toContain('Control');
});

it('no acepta una consulta en el futuro: eso es un turno', function (): void {
    [$usuario, $paciente] = fichaConConsultas();

    $this->actingAs($usuario)
        ->post(route('pacientes.consultas.store', $paciente), ['fecha_hora' => '2026-10-05T10:00'])
        ->assertSessionHasErrors('fecha_hora');

    expect(Consulta::count())->toBe(0);
});

it('no deja colgar la consulta de una enfermedad de otra ficha', function (): void {
    [$usuario, $paciente] = fichaConConsultas();
    $ajena = Enfermedad::factory()->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.consultas.store', $paciente), [
            'fecha_hora' => '2026-09-30T10:00',
            'enfermedad_id' => $ajena->id,
        ])
        ->assertSessionHasErrors('enfermedad_id');
});

it('un médico con consultas no se puede borrar del catálogo', function (): void {
    [$usuario, $paciente] = fichaConConsultas();
    $medico = Medico::factory()->for($usuario, 'usuario')->create();
    Consulta::factory()->for($paciente)->create(['medico_id' => $medico->id]);

    $this->actingAs($usuario)
        ->delete(route('medicos.destroy', $medico))
        ->assertSessionHas('error');

    expect(Medico::find($medico->id))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| La grabación: sin cifrar, validada por extensión y contenido
|--------------------------------------------------------------------------
*/

it('guarda la grabación SIN cifrar, con su duración y el tipo que sale de la extensión', function (): void {
    [$usuario, $paciente] = fichaConConsultas();
    $consulta = Consulta::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->post(route('consultas.audios.store', $consulta), [
            'audio' => grabacion('prueba.m4a', 'Consulta con el Dr.m4a'),
            'duracion_segundos' => 1,
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('exito');

    $audio = $consulta->adjuntos()->sole();

    expect($audio->tipo)->toBe(TipoAdjunto::AudioConsulta)
        ->and($audio->mime)->toBe('audio/mp4')
        ->and($audio->duracion_segundos)->toBe(1)
        ->and($audio->ruta)->toEndWith('.m4a')
        ->and($audio->ruta)->toStartWith('consultas/'.$consulta->id.'/')
        // Byte a byte el original: no está cifrado.
        ->and(Storage::disk('local')->get($audio->ruta))->toBe(file_get_contents(base_path('tests/Fixtures/audio/prueba.m4a')))
        // El nombre original SÍ va cifrado en la base.
        ->and(DB::table('adjuntos')->value('nombre_original'))->not->toContain('Dr');
});

it('acepta mp3 y lo sirve como audio/mpeg', function (): void {
    [$usuario, $paciente] = fichaConConsultas();
    $consulta = Consulta::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->post(route('consultas.audios.store', $consulta), ['audio' => grabacion('prueba.mp3')])
        ->assertSessionHasNoErrors();

    expect($consulta->adjuntos()->sole()->mime)->toBe('audio/mpeg');
});

it('rechaza un PDF disfrazado de m4a y un ogg, que el iPhone no reproduce', function (string $fixture, string $nombre): void {
    [$usuario, $paciente] = fichaConConsultas();
    $consulta = Consulta::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->post(route('consultas.audios.store', $consulta), ['audio' => grabacion($fixture, $nombre)])
        ->assertSessionHasErrors('audio');

    expect($consulta->adjuntos()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'ogg' => ['prueba.ogg', 'grabacion.ogg'],
    'ogg renombrado' => ['prueba.ogg', 'grabacion.m4a'],
]);

it('la subida genérica de documentos NO acepta el tipo audio_consulta', function (): void {
    // Cifraría el archivo y lo marcaría como "sin cifrar": al servirlo saldría el ciphertext.
    [$usuario, $paciente] = fichaConConsultas();

    $this->actingAs($usuario)
        ->post(route('pacientes.adjuntos.store', $paciente), [
            'archivos' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')],
            'tipo' => TipoAdjunto::AudioConsulta->value,
        ])
        ->assertSessionHasErrors('tipo');
});

it('un lector puede escuchar la grabación pero no subir una', function (): void {
    [$usuario, $paciente] = fichaConConsultas();
    $consulta = Consulta::factory()->for($paciente)->create();
    $this->actingAs($usuario)->post(route('consultas.audios.store', $consulta), ['audio' => grabacion()]);
    $audio = $consulta->adjuntos()->sole();

    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)->get(route('adjuntos.show', $audio))->assertOk();
    $this->actingAs($lector)
        ->post(route('consultas.audios.store', $consulta), ['audio' => grabacion()])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Servirla con Range: lo que exige iOS Safari
|--------------------------------------------------------------------------
*/

it('sirve la grabación entera anunciando que acepta pedidos por partes', function (): void {
    [$usuario, $paciente] = fichaConConsultas();
    $consulta = Consulta::factory()->for($paciente)->create();
    $this->actingAs($usuario)->post(route('consultas.audios.store', $consulta), ['audio' => grabacion()]);
    $audio = $consulta->adjuntos()->sole();

    $respuesta = $this->actingAs($usuario)->get(route('adjuntos.show', $audio));

    $respuesta->assertOk()
        ->assertHeader('Content-Type', 'audio/mp4')
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($respuesta->headers->get('Cache-Control'))->toContain('no-store');
});

it('contesta 206 con el pedazo pedido cuando llega un Range', function (): void {
    [$usuario, $paciente] = fichaConConsultas();
    $consulta = Consulta::factory()->for($paciente)->create();
    $this->actingAs($usuario)->post(route('consultas.audios.store', $consulta), ['audio' => grabacion()]);
    $audio = $consulta->adjuntos()->sole();
    $original = file_get_contents(base_path('tests/Fixtures/audio/prueba.m4a'));

    $respuesta = $this->actingAs($usuario)->get(route('adjuntos.show', $audio), ['Range' => 'bytes=0-99']);

    $respuesta->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 0-99/'.strlen($original));
    expect($respuesta->streamedContent())->toBe(substr($original, 0, 100));
});

it('borrar la consulta borra la grabación del disco', function (): void {
    [$usuario, $paciente] = fichaConConsultas();
    $consulta = Consulta::factory()->for($paciente)->create();
    $this->actingAs($usuario)->post(route('consultas.audios.store', $consulta), ['audio' => grabacion()]);
    $ruta = $consulta->adjuntos()->sole()->ruta;
    Storage::disk('local')->assertExists($ruta);

    $this->actingAs($usuario)->delete(route('consultas.destroy', $consulta))->assertRedirect();

    Storage::disk('local')->assertMissing($ruta);
    expect(Adjunto::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Pantallas
|--------------------------------------------------------------------------
*/

it('la pantalla de consultas trae cada una con sus grabaciones, la más reciente arriba', function (): void {
    [$usuario, $paciente] = fichaConConsultas();
    Consulta::factory()->for($paciente)->create(['fecha_hora' => '2026-08-01 13:00:00', 'motivo' => 'Vieja']);
    $nueva = Consulta::factory()->for($paciente)->create(['fecha_hora' => '2026-09-20 13:00:00', 'motivo' => 'Nueva']);
    $this->actingAs($usuario)->post(route('consultas.audios.store', $nueva), [
        'audio' => grabacion(),
        'duracion_segundos' => 1,
    ]);

    $this->actingAs($usuario)
        ->get(route('pacientes.consultas.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->component('consultas/Index')
            ->where('consultas.0.motivo', 'Nueva')
            // 13:00 UTC, en la zona de la cuenta.
            ->where('consultas.0.fechaVisible', '20/09/2026 a las 10:00')
            ->has('consultas.0.audios', 1)
            ->where('consultas.0.audios.0.duracion', 1)
            ->where('consultas.1.motivo', 'Vieja'));
});

it('Grabaciones lista los audios de las consultas de la ficha, y nada de otra', function (): void {
    [$usuario, $paciente] = fichaConConsultas();
    $consulta = Consulta::factory()->for($paciente)->create(['motivo' => 'Control']);
    $this->actingAs($usuario)->post(route('consultas.audios.store', $consulta), ['audio' => grabacion()]);

    [$otro, $ajeno] = fichaConConsultas();
    $deOtro = Consulta::factory()->for($ajeno)->create();
    $this->actingAs($otro)->post(route('consultas.audios.store', $deOtro), ['audio' => grabacion()]);

    $this->actingAs($usuario)
        ->get(route('pacientes.grabaciones.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->component('consultas/Grabaciones')
            ->has('grabaciones', 1)
            ->where('grabaciones.0.consultaId', $consulta->id));
});

it('la ficha de la enfermedad muestra las consultas que se hicieron por ella', function (): void {
    [$usuario, $paciente] = fichaConConsultas();
    $enfermedad = Enfermedad::factory()->for($paciente)->create();
    Consulta::factory()->for($paciente)->create([
        'enfermedad_id' => $enfermedad->id,
        'fecha_hora' => '2026-09-10 13:00:00',
        'motivo' => 'Control de presión',
    ]);
    Consulta::factory()->for($paciente)->create(['motivo' => 'Otra cosa']);

    $this->actingAs($usuario)
        ->get(route('pacientes.enfermedades.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->has('enfermedades.0.consultas', 1)
            ->where('enfermedades.0.consultas.0.motivo', 'Control de presión')
            ->where('enfermedades.0.consultas.0.fechaVisible', '10/09/2026'));
});
