<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Enums\TipoAdjunto;
use App\Enums\TipoCobertura;
use App\Models\Cobertura;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

it('el propietario agrega una cobertura', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.coberturas.store', $paciente), [
            'tipo' => TipoCobertura::ObraSocial->value,
            'entidad' => 'OSDE',
            'plan' => '310',
            'nro_afiliado' => '123456789',
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    $cobertura = $paciente->coberturas()->first();

    expect($cobertura)->not->toBeNull()
        ->and($cobertura->entidad)->toBe('OSDE')
        ->and($cobertura->tipo)->toBe(TipoCobertura::ObraSocial)
        ->and($cobertura->activa)->toBeTrue();
});

it('queda activa por defecto aunque el checkbox no viaje', function (): void {
    // Un checkbox HTML sin marcar no manda el campo -no manda "false"-, así
    // que crear sin mandar `activa` tiene que caer en true, no en false.
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)->post(route('pacientes.coberturas.store', $paciente), [
        'tipo' => TipoCobertura::Prepaga->value,
        'entidad' => 'Swiss Medical',
    ]);

    expect($paciente->coberturas()->first()->activa)->toBeTrue();
});

it('acepta "on", que es lo que manda de verdad un checkbox tildado', function (): void {
    /*
     * Bug real, encontrado a mano en el navegador y no por ningún test: la
     * regla `boolean` de Laravel solo acepta true/false/1/0/"1"/"0", y un
     * <input type="checkbox"> nativo manda el string "on". Sin
     * prepareForValidation() normalizándolo, ESTE envío -el que manda
     * cualquier checkbox tildado de verdad- fallaba la validación con un 302
     * de vuelta y ninguna fila creada. Los tests anteriores no lo agarraban
     * porque mandaban `true` (un bool de PHP) o nada, nunca el string "on".
     */
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.coberturas.store', $paciente), [
            'tipo' => TipoCobertura::ObraSocial->value,
            'entidad' => 'OSDE',
            'activa' => 'on',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($paciente->coberturas()->first()?->activa)->toBeTrue();
});

it('un lector NO puede agregar una cobertura', function (): void {
    $paciente = Paciente::factory()->create();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->post(route('pacientes.coberturas.store', $paciente), [
            'tipo' => TipoCobertura::ObraSocial->value,
            'entidad' => 'OSDE',
        ])
        ->assertForbidden();

    expect($paciente->coberturas()->count())->toBe(0);
});

it('un cuidador sí puede agregar una cobertura', function (): void {
    $paciente = Paciente::factory()->create();
    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->actingAs($cuidador)
        ->post(route('pacientes.coberturas.store', $paciente), [
            'tipo' => TipoCobertura::ObraSocial->value,
            'entidad' => 'OSDE',
        ])
        ->assertRedirect();

    expect($paciente->coberturas()->count())->toBe(1);
});

it('un usuario ajeno NO puede agregar una cobertura', function (): void {
    $paciente = Paciente::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('pacientes.coberturas.store', $paciente), [
            'tipo' => TipoCobertura::ObraSocial->value,
            'entidad' => 'OSDE',
        ])
        ->assertForbidden();
});

it('exige la entidad', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.coberturas.store', $paciente), [
            'tipo' => TipoCobertura::ObraSocial->value,
            'entidad' => '',
        ])
        ->assertSessionHasErrors('entidad');
});

it('exige un tipo válido', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.coberturas.store', $paciente), [
            'tipo' => 'inventado',
            'entidad' => 'OSDE',
        ])
        ->assertSessionHasErrors('tipo');
});

/*
|--------------------------------------------------------------------------
| Cifrado
|--------------------------------------------------------------------------
*/

it('cifra la entidad y el número de afiliado en la base', function (): void {
    $cobertura = Cobertura::factory()->create([
        'entidad' => 'OSDE',
        'nro_afiliado' => '987654321',
    ]);

    $cruda = DB::table('coberturas')->where('id', $cobertura->id)->first();

    expect($cruda->entidad)->not->toContain('OSDE')
        ->and($cruda->nro_afiliado)->not->toContain('987654321')
        ->and($cobertura->fresh()->entidad)->toBe('OSDE')
        ->and($cobertura->fresh()->nro_afiliado)->toBe('987654321');
});

/*
|--------------------------------------------------------------------------
| Unicidad por el índice ciego (no por la columna cifrada)
|--------------------------------------------------------------------------
*/

it('no deja crear dos coberturas de la misma entidad para el mismo paciente', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    Cobertura::factory()->for($paciente)->create(['entidad' => 'OSDE']);

    $this->actingAs($usuario)
        ->post(route('pacientes.coberturas.store', $paciente), [
            'tipo' => TipoCobertura::ObraSocial->value,
            'entidad' => 'OSDE',
        ])
        ->assertSessionHasErrors('entidad');

    expect($paciente->coberturas()->count())->toBe(1);
});

it('la comparación de unicidad ignora mayúsculas y espacios, igual que el índice ciego', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    Cobertura::factory()->for($paciente)->create(['entidad' => 'osde']);

    $this->actingAs($usuario)
        ->post(route('pacientes.coberturas.store', $paciente), [
            'tipo' => TipoCobertura::ObraSocial->value,
            'entidad' => '  OSDE  ',
        ])
        ->assertSessionHasErrors('entidad');
});

it('la misma entidad SÍ se puede repetir en pacientes distintos', function (): void {
    $usuario = User::factory()->create();
    $unPaciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $otroPaciente = Paciente::factory()->for($usuario, 'usuario')->create();
    Cobertura::factory()->for($unPaciente)->create(['entidad' => 'OSDE']);

    $this->actingAs($usuario)
        ->post(route('pacientes.coberturas.store', $otroPaciente), [
            'tipo' => TipoCobertura::ObraSocial->value,
            'entidad' => 'OSDE',
        ])
        ->assertSessionHasNoErrors();

    expect($otroPaciente->coberturas()->count())->toBe(1);
});

it('editar una cobertura sin cambiar la entidad no choca contra sí misma', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $cobertura = Cobertura::factory()->for($paciente)->create(['entidad' => 'OSDE']);

    $this->actingAs($usuario)
        ->put(route('coberturas.update', $cobertura), [
            'tipo' => TipoCobertura::ObraSocial->value,
            'entidad' => 'OSDE',
            'plan' => '410',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($cobertura->fresh()->plan)->toBe('410');
});

it('editar hacia una entidad que ya usa otra cobertura del mismo paciente sí choca', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    Cobertura::factory()->for($paciente)->create(['entidad' => 'OSDE']);
    $swiss = Cobertura::factory()->for($paciente)->create(['entidad' => 'Swiss Medical']);

    $this->actingAs($usuario)
        ->put(route('coberturas.update', $swiss), [
            'tipo' => TipoCobertura::Prepaga->value,
            'entidad' => 'OSDE',
        ])
        ->assertSessionHasErrors('entidad');
});

/*
|--------------------------------------------------------------------------
| Editar y borrar
|--------------------------------------------------------------------------
*/

it('un lector NO puede editar ni borrar una cobertura', function (): void {
    $paciente = Paciente::factory()->create();
    $cobertura = Cobertura::factory()->for($paciente)->create();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->put(route('coberturas.update', $cobertura), [
            'tipo' => TipoCobertura::ObraSocial->value,
            'entidad' => 'Intento',
        ])
        ->assertForbidden();

    $this->actingAs($lector)
        ->delete(route('coberturas.destroy', $cobertura))
        ->assertForbidden();

    expect(Cobertura::find($cobertura->id))->not->toBeNull();
});

it('un cuidador puede desactivar una cobertura destildando "activa"', function (): void {
    $paciente = Paciente::factory()->create();
    $cobertura = Cobertura::factory()->for($paciente)->create(['activa' => true]);
    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->actingAs($cuidador)
        ->put(route('coberturas.update', $cobertura), [
            'tipo' => $cobertura->tipo->value,
            'entidad' => $cobertura->entidad,
            // 'activa' ausente: así es como llega un checkbox destildado.
        ])
        ->assertSessionHasNoErrors();

    expect($cobertura->fresh()->activa)->toBeFalse();
});

it('un cuidador puede borrar una cobertura', function (): void {
    $paciente = Paciente::factory()->create();
    $cobertura = Cobertura::factory()->for($paciente)->create();
    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->actingAs($cuidador)
        ->delete(route('coberturas.destroy', $cobertura))
        ->assertRedirect();

    expect(Cobertura::find($cobertura->id))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Credencial (adjuntos colgados de la cobertura)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    Storage::fake('local');
});

function imagenDePrueba(string $nombre = 'credencial-frente.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($nombre, 200, 120);
}

it('sube la credencial como adjunto colgado de la cobertura, no del paciente', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $cobertura = Cobertura::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->post(route('coberturas.adjuntos.store', $cobertura), [
            'archivos' => [imagenDePrueba()],
            'tipo' => TipoAdjunto::Credencial->value,
        ])
        ->assertRedirect();

    expect($cobertura->adjuntos()->count())->toBe(1)
        ->and($paciente->adjuntos()->count())->toBe(0)
        ->and($cobertura->adjuntos()->first()->tipo)->toBe(TipoAdjunto::Credencial);
});

it('la ficha del paciente trae la credencial anidada en su cobertura', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $cobertura = Cobertura::factory()->for($paciente)->create(['entidad' => 'OSDE']);
    $adjunto = $cobertura->adjuntos()->create([
        'tipo' => TipoAdjunto::Credencial,
        'ruta' => 'coberturas/1/frente.cif',
        'nombre_original' => 'frente.jpg',
        'mime' => 'image/jpeg',
        'tamanio_bytes' => 2048,
    ]);

    $this->actingAs($usuario)
        ->get(route('pacientes.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->has('pacientes.0.coberturas', 1)
            ->where('pacientes.0.coberturas.0.entidad', 'OSDE')
            ->has('pacientes.0.coberturas.0.adjuntos', 1)
            ->where('pacientes.0.coberturas.0.adjuntos.0.tipo', 'Credencial')
            /*
             * Por la ruta APARTE, cacheable por el service worker -no
             * `adjuntos.show`-, que es la que sirve solo credenciales.
             */
            ->where(
                'pacientes.0.coberturas.0.adjuntos.0.url',
                route('credenciales.show', $adjunto),
            )
        );
});

it('un adjunto no-credencial colgado de una cobertura usa la ruta general, no la cacheable', function (): void {
    // Caso defensivo: hoy nada sube algo así, pero si algún día pasa, tiene
    // que seguir sirviéndose -solo que sin la excepción de caché-.
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $cobertura = Cobertura::factory()->for($paciente)->create();
    $adjunto = $cobertura->adjuntos()->create([
        'tipo' => TipoAdjunto::Otro,
        'ruta' => 'coberturas/1/algo.cif',
        'nombre_original' => 'algo.pdf',
        'mime' => 'application/pdf',
        'tamanio_bytes' => 1024,
    ]);

    $this->actingAs($usuario)
        ->get(route('pacientes.index'))
        ->assertInertia(fn ($p) => $p->where(
            'pacientes.0.coberturas.0.adjuntos.0.url',
            route('adjuntos.show', $adjunto),
        ));
});

it('un lector NO puede subir la credencial', function (): void {
    $paciente = Paciente::factory()->create();
    $cobertura = Cobertura::factory()->for($paciente)->create();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->post(route('coberturas.adjuntos.store', $cobertura), [
            'archivos' => [imagenDePrueba()],
            'tipo' => TipoAdjunto::Credencial->value,
        ])
        ->assertForbidden();

    expect($cobertura->adjuntos()->count())->toBe(0);
});
