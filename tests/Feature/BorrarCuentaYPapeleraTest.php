<?php

use App\Console\Commands\VaciarPapelera;
use App\Contracts\TieneArchivos;
use App\Enums\RolPaciente;
use App\Enums\TipoAdjunto;
use App\Models\Adjunto;
use App\Models\Estudio;
use App\Models\Medicamento;
use App\Models\Paciente;
use App\Models\Receta;
use App\Models\Tratamiento;
use App\Models\User;
use App\Services\ArchivoService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Lo que la política de privacidad promete sobre borrar
|--------------------------------------------------------------------------
|
| Eliminar la cuenta borra de verdad, en el momento, con los archivos; y lo que
| se manda a la papelera se borra de verdad a los 30 días. Antes, eliminar una
| cuenta con un tratamiento cargado daba un 500 (medido contra MySQL), y lo que
| quedaba en la papelera no se borraba nunca.
|
*/

beforeEach(function (): void {
    Storage::fake('local');
});

function adjuntoConArchivo(Model&TieneArchivos $duenio, TipoAdjunto $tipo = TipoAdjunto::InformeEstudio): Adjunto
{
    $pdf = "%PDF-1.4\n1 0 obj\n<</Type/Catalog>>\nendobj\ntrailer<</Root 1 0 R>>\n%%EOF\n".uniqid();

    return $duenio->adjuntos()->create([
        ...app(ArchivoService::class)->guardarContenido($pdf, 'informe.pdf', $duenio->carpetaDeArchivos()),
        'tipo' => $tipo,
    ]);
}

function borrarCuenta(User $usuario): void
{
    test()->actingAs($usuario)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect('/');
}

it('⚠️ elimina una cuenta con un tratamiento de su propio medicamento (antes daba 500)', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $medicamento = Medicamento::factory()->for($usuario, 'usuario')->create();
    Tratamiento::factory()->for($paciente)->create(['medicamento_id' => $medicamento->id]);

    borrarCuenta($usuario);

    expect(User::find($usuario->id))->toBeNull()
        ->and(Paciente::withTrashed()->find($paciente->id))->toBeNull()
        ->and(Medicamento::withTrashed()->find($medicamento->id))->toBeNull()
        ->and(Tratamiento::withTrashed()->count())->toBe(0);
});

it('se lleva los archivos de sus fichas del disco, también los de la papelera', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $estudio = Estudio::factory()->for($paciente)->create();
    $delEstudio = adjuntoConArchivo($estudio);
    $delPaciente = adjuntoConArchivo($paciente, TipoAdjunto::Otro);
    $estudio->delete(); // en la papelera: igual tiene que irse

    borrarCuenta($usuario);

    Storage::disk('local')->assertMissing($delEstudio->ruta);
    Storage::disk('local')->assertMissing($delPaciente->ruta);
    expect(Adjunto::withTrashed()->count())->toBe(0);
});

it('se lleva las recetas de su casilla con sus archivos', function (): void {
    $usuario = User::factory()->create();
    $receta = Receta::factory()->for($usuario, 'usuario')->create();
    $pdf = adjuntoConArchivo($receta, TipoAdjunto::Receta);

    borrarCuenta($usuario);

    Storage::disk('local')->assertMissing($pdf->ruta);
    expect(Receta::withTrashed()->count())->toBe(0);
});

it('cierra las sesiones de la cuenta en cualquier dispositivo', function (): void {
    $usuario = User::factory()->create();
    DB::table('sessions')->insert([
        'id' => 'otro-dispositivo', 'user_id' => $usuario->id, 'payload' => '', 'last_activity' => time(),
    ]);

    borrarCuenta($usuario);

    expect(DB::table('sessions')->where('user_id', $usuario->id)->count())->toBe(0);
});

it('la ficha de la que era dueña deja de existir también para quien la compartía', function (): void {
    $duenio = User::factory()->create();
    $cuidador = User::factory()->create();
    $paciente = Paciente::factory()->for($duenio, 'usuario')->create();
    $paciente->cuidadores()->attach($cuidador->id, ['rol' => RolPaciente::Cuidador->value]);

    borrarCuenta($duenio);

    expect(Paciente::withTrashed()->find($paciente->id))->toBeNull()
        ->and(User::find($cuidador->id))->not->toBeNull();
});

it('lo que un cuidador cargó con su catálogo en una ficha ajena pasa al catálogo del dueño', function (): void {
    $duenio = User::factory()->create();
    $cuidador = User::factory()->create();
    $paciente = Paciente::factory()->for($duenio, 'usuario')->create();
    $paciente->cuidadores()->attach($cuidador->id, ['rol' => RolPaciente::Cuidador->value]);

    $delCuidador = Medicamento::factory()->for($cuidador, 'usuario')->create(['nombre_comercial' => 'Enalapril']);
    $tratamiento = Tratamiento::factory()->for($paciente)->create(['medicamento_id' => $delCuidador->id]);

    borrarCuenta($cuidador);

    $tratamiento->refresh();
    $copia = Medicamento::findOrFail($tratamiento->medicamento_id);

    expect($tratamiento->exists)->toBeTrue()
        ->and($copia->usuario_id)->toBe($duenio->id)
        ->and($copia->nombre_comercial)->toBe('Enalapril')
        ->and(Medicamento::withTrashed()->find($delCuidador->id))->toBeNull();
});

it('si el dueño ya tenía uno con ese nombre, usa el suyo en vez de duplicarlo', function (): void {
    $duenio = User::factory()->create();
    $cuidador = User::factory()->create();
    $paciente = Paciente::factory()->for($duenio, 'usuario')->create();
    $paciente->cuidadores()->attach($cuidador->id, ['rol' => RolPaciente::Cuidador->value]);

    $delDuenio = Medicamento::factory()->for($duenio, 'usuario')->create(['nombre_comercial' => 'Enalapril']);
    $delCuidador = Medicamento::factory()->for($cuidador, 'usuario')->create(['nombre_comercial' => 'enalapril']);
    $tratamiento = Tratamiento::factory()->for($paciente)->create(['medicamento_id' => $delCuidador->id]);

    borrarCuenta($cuidador);

    expect($tratamiento->refresh()->medicamento_id)->toBe($delDuenio->id)
        ->and(Medicamento::where('usuario_id', $duenio->id)->count())->toBe(1);
});

it('la papelera: a los 31 días borra el registro y su archivo; a los 29 no toca nada', function (): void {
    $paciente = Paciente::factory()->create();
    $viejo = Estudio::factory()->for($paciente)->create();
    $reciente = Estudio::factory()->for($paciente)->create();
    $archivoViejo = adjuntoConArchivo($viejo);
    $archivoReciente = adjuntoConArchivo($reciente);

    $this->travel(-31)->days();
    $viejo->delete();
    $this->travelBack();
    $this->travel(-29)->days();
    $reciente->delete();
    $this->travelBack();

    $this->artisan('misalud:vaciar-papelera')->assertSuccessful();

    expect(Estudio::withTrashed()->find($viejo->id))->toBeNull()
        ->and(Estudio::withTrashed()->find($reciente->id))->not->toBeNull();
    Storage::disk('local')->assertMissing($archivoViejo->ruta);
    Storage::disk('local')->assertExists($archivoReciente->ruta);
});

it('una ficha en la papelera hace más de 30 días se va con todo su historial', function (): void {
    $paciente = Paciente::factory()->create();
    $estudio = Estudio::factory()->for($paciente)->create();
    $archivo = adjuntoConArchivo($estudio);

    $this->travel(-31)->days();
    $paciente->delete();
    $this->travelBack();

    $this->artisan('misalud:vaciar-papelera')->assertSuccessful();

    expect(Paciente::withTrashed()->find($paciente->id))->toBeNull()
        ->and(Estudio::withTrashed()->find($estudio->id))->toBeNull();
    Storage::disk('local')->assertMissing($archivo->ruta);
});

it('de una receta vieja en la papelera se va el PDF pero queda la huella que evita reimportarla', function (): void {
    $receta = Receta::factory()->create();
    $pdf = adjuntoConArchivo($receta, TipoAdjunto::Receta);

    $this->travel(-31)->days();
    $receta->delete();
    $this->travelBack();

    $this->artisan('misalud:vaciar-papelera')->assertSuccessful();

    expect(Receta::withTrashed()->find($receta->id))->not->toBeNull();
    Storage::disk('local')->assertMissing($pdf->ruta);
});

it('el ensayo en seco no borra nada', function (): void {
    $estudio = Estudio::factory()->create();
    $this->travel(-31)->days();
    $estudio->delete();
    $this->travelBack();

    $this->artisan('misalud:vaciar-papelera --seco')->assertSuccessful();

    expect(Estudio::withTrashed()->find($estudio->id))->not->toBeNull();
});

it('el plazo de la papelera es el que promete la política', function (): void {
    expect(VaciarPapelera::DIAS)->toBe(30)
        ->and((string) file_get_contents(resource_path('legales/privacidad.md')))->toContain('**30 días**');
});
