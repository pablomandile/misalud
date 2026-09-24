<?php

declare(strict_types=1);

use App\Enums\EstadoOrdenEstudio;
use App\Enums\RolPaciente;
use App\Enums\TipoAdjunto;
use App\Models\Cobertura;
use App\Models\Medicamento;
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
function fichaConOrdenes(): array
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

it('carga una orden en la ficha del paciente', function (): void {
    [$usuario, $paciente] = fichaConOrdenes();

    $this->actingAs($usuario)
        ->post(route('pacientes.ordenes.store', $paciente), [
            'estudio_solicitado' => 'Análisis de sangre completo',
            'fecha' => '2026-09-20',
            'estado' => EstadoOrdenEstudio::Pendiente->value,
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    $orden = OrdenEstudio::first();

    expect($orden->paciente_id)->toBe($paciente->id)
        ->and($orden->estudio_solicitado)->toBe('Análisis de sangre completo')
        ->and($orden->estado)->toBe(EstadoOrdenEstudio::Pendiente);
});

it('NO deja elegir en la ficha de quién escribir', function (): void {
    [$usuario, $paciente] = fichaConOrdenes();
    $ajeno = Paciente::factory()->create();

    $this->actingAs($usuario)->post(route('pacientes.ordenes.store', $paciente), [
        'paciente_id' => $ajeno->id,
        'estudio_solicitado' => 'Radiografía',
        'fecha' => '2026-09-20',
        'estado' => EstadoOrdenEstudio::Pendiente->value,
    ]);

    expect(OrdenEstudio::first()->paciente_id)->toBe($paciente->id);
});

it('cifra lo que pidió el médico y las notas', function (): void {
    $orden = OrdenEstudio::factory()->create([
        'estudio_solicitado' => 'Estudio Confidencial',
        'notas' => 'Detalle reservado',
    ]);

    $crudo = DB::table('ordenes_estudio')->where('id', $orden->id)->first();

    expect($crudo->estudio_solicitado)->not->toContain('Confidencial')
        ->and($crudo->notas)->not->toContain('reservado')
        // El estado y la fecha quedan en claro: son por donde se ordena.
        ->and($crudo->estado)->toBe('pendiente')
        ->and($orden->fresh()->estudio_solicitado)->toBe('Estudio Confidencial');
});

it('pone las PENDIENTES primero, que es para lo que existe la pantalla', function (): void {
    [$usuario, $paciente] = fichaConOrdenes();
    // La hecha es más reciente: si el orden fuera solo por fecha, iría arriba.
    OrdenEstudio::factory()->for($paciente)->hecha()
        ->create(['estudio_solicitado' => 'Ya hecha', 'fecha' => '2026-09-20']);
    OrdenEstudio::factory()->for($paciente)
        ->create(['estudio_solicitado' => 'Falta hacer', 'fecha' => '2026-09-01']);

    $this->actingAs($usuario)
        ->get(route('pacientes.ordenes.index', $paciente))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('ordenes/Index')
            ->has('ordenes', 2)
            ->where('ordenes.0.estudio_solicitado', 'Falta hacer')
            ->where('ordenes.0.estaPendiente', true)
            ->where('ordenes.1.estaPendiente', false)
        );
});

it('solo la pendiente cuenta como pendiente', function (): void {
    expect(EstadoOrdenEstudio::Pendiente->estaPendiente())->toBeTrue()
        ->and(EstadoOrdenEstudio::Hecha->estaPendiente())->toBeFalse()
        ->and(EstadoOrdenEstudio::Anulada->estaPendiente())->toBeFalse();
});

it('exige lo que pidió el médico, la fecha y el estado', function (): void {
    [$usuario, $paciente] = fichaConOrdenes();

    $this->actingAs($usuario)
        ->post(route('pacientes.ordenes.store', $paciente), [])
        ->assertSessionHasErrors(['estudio_solicitado', 'fecha', 'estado']);
});

it('rechaza un estado inventado', function (): void {
    [$usuario, $paciente] = fichaConOrdenes();

    $this->actingAs($usuario)
        ->post(route('pacientes.ordenes.store', $paciente), [
            'estudio_solicitado' => 'Algo',
            'fecha' => '2026-09-20',
            'estado' => 'inventado',
        ])
        ->assertSessionHasErrors('estado');
});

/*
|--------------------------------------------------------------------------
| La fecha: una orden es un papel que YA existe
|--------------------------------------------------------------------------
*/

it('rechaza una orden con fecha futura', function (): void {
    [$usuario, $paciente] = fichaConOrdenes();

    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00', 'UTC'));

    $this->actingAs($usuario)
        ->post(route('pacientes.ordenes.store', $paciente), [
            'estudio_solicitado' => 'Algo',
            'fecha' => '2026-09-25',
            'estado' => EstadoOrdenEstudio::Pendiente->value,
        ])
        ->assertSessionHasErrors('fecha');
});

it('acepta HOY aunque sea de noche en Buenos Aires', function (): void {
    // La trampa de `hoy()` vs `hoyCalendario()`, ya envuelta en FechaNoFutura.
    [$usuario, $paciente] = fichaConOrdenes();

    $this->travelTo(CarbonImmutable::parse('2026-09-25 02:00', 'UTC'));

    $this->actingAs($usuario)
        ->post(route('pacientes.ordenes.store', $paciente), [
            'estudio_solicitado' => 'Algo',
            'fecha' => '2026-09-24',
            'estado' => EstadoOrdenEstudio::Pendiente->value,
        ])
        ->assertSessionHasNoErrors();

    expect(OrdenEstudio::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| El médico: mismo criterio de siempre
|--------------------------------------------------------------------------
*/

it('NO deja usar el médico de otro usuario', function (): void {
    [$usuario, $paciente] = fichaConOrdenes();
    $ajeno = Medico::factory()->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.ordenes.store', $paciente), [
            'estudio_solicitado' => 'Algo',
            'fecha' => '2026-09-20',
            'estado' => EstadoOrdenEstudio::Pendiente->value,
            'medico_id' => $ajeno->id,
        ])
        ->assertSessionHasErrors('medico_id');
});

it('una orden sobrevive al borrado del médico', function (): void {
    // `nullOnDelete`: quién la indicó es metadato, no es lo que la hace
    // legible (la regla de la Etapa 7).
    $medico = Medico::factory()->create();
    $orden = OrdenEstudio::factory()->create(['medico_id' => $medico->id]);

    $medico->forceDelete();

    expect($orden->fresh())->not->toBeNull()
        ->and($orden->fresh()->medico_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| El papel: primer registro clínico con archivos propios
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    Storage::fake('local');
});

function papelDePrueba(string $nombre = 'orden.pdf'): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($ruta, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

    return new UploadedFile($ruta, $nombre, 'application/pdf', null, true);
}

it('sube el papel de la orden como adjunto colgado de ella', function (): void {
    [$usuario, $paciente] = fichaConOrdenes();
    $orden = OrdenEstudio::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->post(route('ordenes.adjuntos.store', $orden), [
            'archivos' => [papelDePrueba()],
            'tipo' => TipoAdjunto::OrdenEstudio->value,
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    expect($orden->adjuntos()->count())->toBe(1)
        ->and($orden->adjuntos()->first()->tipo)->toBe(TipoAdjunto::OrdenEstudio)
        // El paciente NO se lleva el archivo: cuelga de la orden.
        ->and($paciente->adjuntos()->count())->toBe(0);
});

it('lo guarda bajo la carpeta que declara el modelo', function (): void {
    // `carpetaDeArchivos()` lo declara el modelo y no lo arma el controlador,
    // para que dos dueños no terminen escribiendo en la misma carpeta.
    [$usuario, $paciente] = fichaConOrdenes();
    $orden = OrdenEstudio::factory()->for($paciente)->create();

    $this->actingAs($usuario)->post(route('ordenes.adjuntos.store', $orden), [
        'archivos' => [papelDePrueba()],
        'tipo' => TipoAdjunto::OrdenEstudio->value,
    ]);

    expect($orden->adjuntos()->first()->ruta)->toStartWith("ordenes_estudio/{$orden->id}/");
});

it('el listado trae el papel con su URL', function (): void {
    [$usuario, $paciente] = fichaConOrdenes();
    $orden = OrdenEstudio::factory()->for($paciente)->create();
    $adjunto = $orden->adjuntos()->create([
        'tipo' => TipoAdjunto::OrdenEstudio,
        'ruta' => 'ordenes_estudio/1/orden.cif',
        'nombre_original' => 'orden.pdf',
        'mime' => 'application/pdf',
        'tamanio_bytes' => 2048,
    ]);

    $this->actingAs($usuario)
        ->get(route('pacientes.ordenes.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->has('ordenes.0.adjuntos', 1)
            ->where('ordenes.0.adjuntos.0.url', route('adjuntos.show', $adjunto))
        );
});

it('un lector NO puede subir el papel', function (): void {
    [, $paciente] = fichaConOrdenes();
    $orden = OrdenEstudio::factory()->for($paciente)->create();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->post(route('ordenes.adjuntos.store', $orden), [
            'archivos' => [papelDePrueba()],
            'tipo' => TipoAdjunto::OrdenEstudio->value,
        ])
        ->assertForbidden();
});

it('los otros tres dueños de archivos siguen andando igual', function (): void {
    /*
     * El cuerpo de la subida pasó a estar una sola vez (`guardarEn`), así
     * que este test cuida que el refactor no haya movido de carpeta lo que
     * ya estaba guardado.
     */
    $paciente = Paciente::factory()->create();
    $cobertura = Cobertura::factory()->for($paciente)->create();
    $medicamento = Medicamento::factory()->create();

    expect($paciente->carpetaDeArchivos())->toBe("pacientes/{$paciente->id}")
        ->and($cobertura->carpetaDeArchivos())->toBe("coberturas/{$cobertura->id}")
        ->and($medicamento->carpetaDeArchivos())->toBe("medicamentos/{$medicamento->id}");
});

/*
|--------------------------------------------------------------------------
| Autorización
|--------------------------------------------------------------------------
*/

it('un usuario ajeno no ve ni carga órdenes', function (): void {
    [, $paciente] = fichaConOrdenes();
    $ajeno = User::factory()->create();

    $this->actingAs($ajeno)
        ->get(route('pacientes.ordenes.index', $paciente))
        ->assertForbidden();

    $this->actingAs($ajeno)
        ->post(route('pacientes.ordenes.store', $paciente), [
            'estudio_solicitado' => 'Intento',
            'fecha' => '2026-09-20',
            'estado' => EstadoOrdenEstudio::Pendiente->value,
        ])
        ->assertForbidden();
});

it('un lector ve pero no carga ni borra', function (): void {
    [, $paciente] = fichaConOrdenes();
    $orden = OrdenEstudio::factory()->for($paciente)->create();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->get(route('pacientes.ordenes.index', $paciente))
        ->assertOk();

    $this->actingAs($lector)
        ->delete(route('ordenes.destroy', $orden))
        ->assertForbidden();
});

it('marcar una orden como hecha la saca de pendientes', function (): void {
    [$usuario, $paciente] = fichaConOrdenes();
    $orden = OrdenEstudio::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->put(route('ordenes.update', $orden), [
            'estudio_solicitado' => $orden->estudio_solicitado,
            'fecha' => $orden->fecha->format('Y-m-d'),
            'estado' => EstadoOrdenEstudio::Hecha->value,
        ])
        ->assertSessionHasNoErrors();

    expect($orden->fresh()->estado)->toBe(EstadoOrdenEstudio::Hecha)
        ->and($orden->fresh()->estado->estaPendiente())->toBeFalse();
});

it('el dueño borra su orden', function (): void {
    [$usuario, $paciente] = fichaConOrdenes();
    $orden = OrdenEstudio::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->delete(route('ordenes.destroy', $orden))
        ->assertRedirect()
        ->assertSessionHas('exito');

    expect(OrdenEstudio::find($orden->id))->toBeNull();
});

it('exige sesión', function (): void {
    [, $paciente] = fichaConOrdenes();

    $this->get(route('pacientes.ordenes.index', $paciente))->assertRedirect(route('login'));
});
