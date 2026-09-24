<?php

declare(strict_types=1);

use App\Enums\EstadoOrdenEstudio;
use App\Enums\RolPaciente;
use App\Enums\TipoAdjunto;
use App\Models\Centro;
use App\Models\Enfermedad;
use App\Models\Estudio;
use App\Models\Medico;
use App\Models\OrdenEstudio;
use App\Models\Paciente;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * @return array{0: User, 1: Paciente}
 */
function fichaConEstudios(): array
{
    $usuario = User::factory()->create(['zona_horaria' => 'America/Argentina/Buenos_Aires']);
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    return [$usuario, $paciente];
}

/*
|--------------------------------------------------------------------------
| Alta, listado y cifrado
|--------------------------------------------------------------------------
*/

it('carga un estudio en la ficha del paciente', function (): void {
    [$usuario, $paciente] = fichaConEstudios();

    $this->actingAs($usuario)
        ->post(route('pacientes.estudios.store', $paciente), [
            'tipo' => 'Análisis de sangre completo',
            'fecha' => '2026-09-20',
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    $estudio = Estudio::first();

    expect($estudio->paciente_id)->toBe($paciente->id)
        ->and($estudio->tipo)->toBe('Análisis de sangre completo');
});

it('NO deja elegir en la ficha de quién escribir', function (): void {
    [$usuario, $paciente] = fichaConEstudios();
    $ajeno = Paciente::factory()->create();

    $this->actingAs($usuario)->post(route('pacientes.estudios.store', $paciente), [
        'paciente_id' => $ajeno->id,
        'tipo' => 'Radiografía',
        'fecha' => '2026-09-20',
    ]);

    expect(Estudio::first()->paciente_id)->toBe($paciente->id);
});

it('cifra el tipo y las notas', function (): void {
    $estudio = Estudio::factory()->create([
        'tipo' => 'Estudio Confidencial',
        'notas' => 'Detalle reservado',
    ]);

    $crudo = DB::table('estudios')->where('id', $estudio->id)->first();

    expect($crudo->tipo)->not->toContain('Confidencial')
        ->and($crudo->notas)->not->toContain('reservado')
        ->and($estudio->fresh()->tipo)->toBe('Estudio Confidencial');
});

it('rechaza un estudio con fecha futura: es algo que YA se hizo', function (): void {
    [$usuario, $paciente] = fichaConEstudios();

    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00', 'UTC'));

    $this->actingAs($usuario)
        ->post(route('pacientes.estudios.store', $paciente), [
            'tipo' => 'Algo',
            'fecha' => '2026-09-25',
        ])
        ->assertSessionHasErrors('fecha');
});

it('acepta HOY aunque sea de noche en Buenos Aires', function (): void {
    [$usuario, $paciente] = fichaConEstudios();

    $this->travelTo(CarbonImmutable::parse('2026-09-25 02:00', 'UTC'));

    $this->actingAs($usuario)
        ->post(route('pacientes.estudios.store', $paciente), [
            'tipo' => 'Algo',
            'fecha' => '2026-09-24',
        ])
        ->assertSessionHasNoErrors();

    expect(Estudio::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Médico, centro y enfermedad: mismo criterio de siempre
|--------------------------------------------------------------------------
*/

it('NO deja usar el médico ni el centro de otro usuario', function (): void {
    [$usuario, $paciente] = fichaConEstudios();
    $medicoAjeno = Medico::factory()->create();
    $centroAjeno = Centro::factory()->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.estudios.store', $paciente), [
            'tipo' => 'Algo',
            'fecha' => '2026-09-20',
            'medico_id' => $medicoAjeno->id,
        ])
        ->assertSessionHasErrors('medico_id');

    $this->actingAs($usuario)
        ->post(route('pacientes.estudios.store', $paciente), [
            'tipo' => 'Algo',
            'fecha' => '2026-09-20',
            'centro_id' => $centroAjeno->id,
        ])
        ->assertSessionHasErrors('centro_id');
});

it('un estudio sobrevive al borrado del médico y del centro', function (): void {
    // nullOnDelete: son metadato, no lo que hace legible al registro.
    $medico = Medico::factory()->create();
    $centro = Centro::factory()->create();
    $estudio = Estudio::factory()->create(['medico_id' => $medico->id, 'centro_id' => $centro->id]);

    $medico->forceDelete();
    $centro->forceDelete();

    expect($estudio->fresh())->not->toBeNull()
        ->and($estudio->fresh()->medico_id)->toBeNull()
        ->and($estudio->fresh()->centro_id)->toBeNull();
});

it('NO deja vincular la enfermedad de otro paciente', function (): void {
    [$usuario, $paciente] = fichaConEstudios();
    $deOtro = Enfermedad::factory()->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.estudios.store', $paciente), [
            'tipo' => 'Algo',
            'fecha' => '2026-09-20',
            'enfermedad_id' => $deOtro->id,
        ])
        ->assertSessionHasErrors('enfermedad_id');
});

/*
|--------------------------------------------------------------------------
| El circuito orden -> estudio, y su regla de una sola dirección
|--------------------------------------------------------------------------
*/

it('vincular una orden al crear el estudio la marca HECHA', function (): void {
    [$usuario, $paciente] = fichaConEstudios();
    $orden = OrdenEstudio::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.estudios.store', $paciente), [
            'tipo' => 'Análisis de sangre',
            'fecha' => '2026-09-20',
            'orden_estudio_id' => $orden->id,
        ])
        ->assertSessionHasNoErrors();

    $estudio = Estudio::first();

    expect($orden->fresh()->estudio_id)->toBe($estudio->id)
        ->and($orden->fresh()->estado)->toBe(EstadoOrdenEstudio::Hecha);
});

it('sin vincular ninguna orden, el estudio se crea igual', function (): void {
    [$usuario, $paciente] = fichaConEstudios();

    $this->actingAs($usuario)
        ->post(route('pacientes.estudios.store', $paciente), [
            'tipo' => 'Análisis de sangre',
            'fecha' => '2026-09-20',
        ])
        ->assertSessionHasNoErrors();

    expect(Estudio::count())->toBe(1);
});

it('NO deja vincular la orden de otro paciente', function (): void {
    [$usuario, $paciente] = fichaConEstudios();
    $ordenAjena = OrdenEstudio::factory()->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.estudios.store', $paciente), [
            'tipo' => 'Algo',
            'fecha' => '2026-09-20',
            'orden_estudio_id' => $ordenAjena->id,
        ])
        ->assertSessionHasErrors('orden_estudio_id');

    expect(Estudio::count())->toBe(0);
});

it('NO deja vincular una orden que ya tiene otro estudio', function (): void {
    // Una vez usada, no se puede vincular dos veces.
    [$usuario, $paciente] = fichaConEstudios();
    $otroEstudio = Estudio::factory()->for($paciente)->create();
    $orden = OrdenEstudio::factory()->for($paciente)->create(['estudio_id' => $otroEstudio->id]);

    $this->actingAs($usuario)
        ->post(route('pacientes.estudios.store', $paciente), [
            'tipo' => 'Algo',
            'fecha' => '2026-09-20',
            'orden_estudio_id' => $orden->id,
        ])
        ->assertSessionHasErrors('orden_estudio_id');
});

it('borrar el estudio NO borra la orden, solo el vínculo', function (): void {
    // El papel es un dato por derecho propio: la orden existió igual.
    [$usuario, $paciente] = fichaConEstudios();
    $orden = OrdenEstudio::factory()->for($paciente)->create();

    $this->actingAs($usuario)->post(route('pacientes.estudios.store', $paciente), [
        'tipo' => 'Algo',
        'fecha' => '2026-09-20',
        'orden_estudio_id' => $orden->id,
    ]);
    $estudio = Estudio::firstOrFail();

    $estudio->forceDelete();

    expect($orden->fresh())->not->toBeNull()
        ->and($orden->fresh()->estudio_id)->toBeNull()
        // El estado NO se revierte: es una relación de una sola dirección.
        ->and($orden->fresh()->estado)->toBe(EstadoOrdenEstudio::Hecha);
});

it('una orden ya vinculada no aparece más como disponible', function (): void {
    [$usuario, $paciente] = fichaConEstudios();
    $orden = OrdenEstudio::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->get(route('pacientes.estudios.index', $paciente))
        ->assertInertia(fn ($p) => $p->has('ordenesDisponibles', 1));

    $this->actingAs($usuario)->post(route('pacientes.estudios.store', $paciente), [
        'tipo' => 'Algo',
        'fecha' => '2026-09-20',
        'orden_estudio_id' => $orden->id,
    ]);

    $this->actingAs($usuario)
        ->get(route('pacientes.estudios.index', $paciente))
        ->assertInertia(fn ($p) => $p->has('ordenesDisponibles', 0));
});

/*
|--------------------------------------------------------------------------
| El informe: quinto dueño de archivos
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    Storage::fake('local');
});

function informeDePrueba(string $nombre = 'informe.pdf'): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($ruta, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

    return new UploadedFile($ruta, $nombre, 'application/pdf', null, true);
}

it('sube el informe como adjunto colgado del estudio', function (): void {
    [$usuario, $paciente] = fichaConEstudios();
    $estudio = Estudio::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->post(route('estudios.adjuntos.store', $estudio), [
            'archivos' => [informeDePrueba()],
            'tipo' => TipoAdjunto::InformeEstudio->value,
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    expect($estudio->adjuntos()->count())->toBe(1)
        ->and($estudio->adjuntos()->first()->tipo)->toBe(TipoAdjunto::InformeEstudio)
        ->and($estudio->adjuntos()->first()->ruta)->toStartWith("estudios/{$estudio->id}/");
});

it('un lector NO puede subir el informe', function (): void {
    [, $paciente] = fichaConEstudios();
    $estudio = Estudio::factory()->for($paciente)->create();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->post(route('estudios.adjuntos.store', $estudio), [
            'archivos' => [informeDePrueba()],
            'tipo' => TipoAdjunto::InformeEstudio->value,
        ])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Autorización
|--------------------------------------------------------------------------
*/

it('un usuario ajeno no ve ni carga estudios', function (): void {
    [, $paciente] = fichaConEstudios();
    $ajeno = User::factory()->create();

    $this->actingAs($ajeno)
        ->get(route('pacientes.estudios.index', $paciente))
        ->assertForbidden();

    $this->actingAs($ajeno)
        ->post(route('pacientes.estudios.store', $paciente), [
            'tipo' => 'Intento',
            'fecha' => '2026-09-20',
        ])
        ->assertForbidden();
});

it('un lector ve pero no carga ni borra', function (): void {
    [, $paciente] = fichaConEstudios();
    $estudio = Estudio::factory()->for($paciente)->create();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->get(route('pacientes.estudios.index', $paciente))
        ->assertOk();

    $this->actingAs($lector)
        ->delete(route('estudios.destroy', $estudio))
        ->assertForbidden();
});

it('el dueño borra su estudio', function (): void {
    [$usuario, $paciente] = fichaConEstudios();
    $estudio = Estudio::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->delete(route('estudios.destroy', $estudio))
        ->assertRedirect()
        ->assertSessionHas('exito');

    expect(Estudio::find($estudio->id))->toBeNull();
});

it('exige sesión', function (): void {
    [, $paciente] = fichaConEstudios();

    $this->get(route('pacientes.estudios.index', $paciente))->assertRedirect(route('login'));
});
