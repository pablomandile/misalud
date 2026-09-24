<?php

declare(strict_types=1);

use App\Enums\TipoAdjunto;
use App\Models\Medicamento;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| El catálogo es del USUARIO, no de un paciente (copiado de médicos)
|--------------------------------------------------------------------------
*/

it('lista los medicamentos propios y NO los de otro usuario', function (): void {
    $usuario = User::factory()->create();
    $mio = Medicamento::factory()->for($usuario, 'usuario')->create(['nombre_comercial' => 'Mío']);
    Medicamento::factory()->create(['nombre_comercial' => 'Ajeno']);

    $this->actingAs($usuario)
        ->get(route('medicamentos.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('catalogos/Medicamentos')
            ->has('registros', 1)
            ->where('registros.0.id', $mio->id)
        );
});

it('lista también las semillas compartidas', function (): void {
    $usuario = User::factory()->create();
    Medicamento::factory()->for($usuario, 'usuario')->create();
    Medicamento::factory()->semilla()->create();

    $this->actingAs($usuario)
        ->get(route('medicamentos.index'))
        ->assertInertia(fn ($p) => $p->has('registros', 2));
});

it('ordena por nombre comercial, que está cifrado', function (): void {
    $usuario = User::factory()->create();
    Medicamento::factory()->for($usuario, 'usuario')->create(['nombre_comercial' => 'Zeta']);
    Medicamento::factory()->for($usuario, 'usuario')->create(['nombre_comercial' => 'alfa']);

    $this->actingAs($usuario)
        ->get(route('medicamentos.index'))
        ->assertInertia(fn ($p) => $p
            ->where('registros.0.nombre_comercial', 'alfa')
            ->where('registros.1.nombre_comercial', 'Zeta')
        );
});

it('cifra el nombre comercial y la droga en la base', function (): void {
    $medicamento = Medicamento::factory()->create([
        'nombre_comercial' => 'Ibuprofeno Confidencial',
        'droga' => 'Ibuprofeno',
    ]);

    $crudo = DB::table('medicamentos')->where('id', $medicamento->id)->first();

    expect($crudo->nombre_comercial)->not->toContain('Confidencial')
        ->and($crudo->droga)->not->toContain('Ibuprofeno')
        ->and($medicamento->fresh()->nombre_comercial)->toBe('Ibuprofeno Confidencial');
});

/*
|--------------------------------------------------------------------------
| Alta, unicidad y semillas: mismo patrón que médicos y centros
|--------------------------------------------------------------------------
*/

it('crea un medicamento en el catálogo del usuario que lo carga', function (): void {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->post(route('medicamentos.store'), [
            'nombre_comercial' => 'Actron',
            'droga' => 'Ibuprofeno',
            'para_que_sirve' => 'Dolor y fiebre',
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    $medicamento = Medicamento::first();

    expect($medicamento->nombre_comercial)->toBe('Actron')
        ->and($medicamento->droga)->toBe('Ibuprofeno')
        ->and($medicamento->usuario_id)->toBe($usuario->id);
});

it('exige el nombre comercial', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('medicamentos.store'), ['nombre_comercial' => ''])
        ->assertSessionHasErrors('nombre_comercial');
});

it('no deja dos medicamentos con el mismo nombre comercial en MI catálogo', function (): void {
    $usuario = User::factory()->create();
    Medicamento::factory()->for($usuario, 'usuario')->create(['nombre_comercial' => 'Actron']);

    $this->actingAs($usuario)
        ->post(route('medicamentos.store'), ['nombre_comercial' => '  ACTRON  '])
        ->assertSessionHasErrors('nombre_comercial');

    expect(Medicamento::count())->toBe(1);
});

it('nadie puede editar ni borrar una semilla compartida', function (): void {
    $semilla = Medicamento::factory()->semilla()->create();
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->put(route('medicamentos.update', $semilla), ['nombre_comercial' => 'Intento'])
        ->assertForbidden();

    $this->actingAs($usuario)
        ->delete(route('medicamentos.destroy', $semilla))
        ->assertForbidden();
});

it('duplicar una semilla la copia al catálogo propio', function (): void {
    $usuario = User::factory()->create();
    $semilla = Medicamento::factory()->semilla()->create(['nombre_comercial' => 'Genérico']);

    $this->actingAs($usuario)
        ->post(route('medicamentos.duplicar', $semilla))
        ->assertRedirect()
        ->assertSessionHas('exito');

    $copia = Medicamento::where('usuario_id', $usuario->id)->first();

    expect($copia)->not->toBeNull()
        ->and($copia->nombre_comercial)->toBe('Genérico')
        ->and($semilla->fresh()->usuario_id)->toBeNull();
});

it('un usuario ajeno no puede editar ni borrar mi medicamento', function (): void {
    $medicamento = Medicamento::factory()->create(['nombre_comercial' => 'Mío']);

    $this->actingAs(User::factory()->create())
        ->put(route('medicamentos.update', $medicamento), ['nombre_comercial' => 'Intento'])
        ->assertForbidden();

    expect($medicamento->fresh()->nombre_comercial)->toBe('Mío');
});

it('exige sesión', function (): void {
    $this->get(route('medicamentos.index'))->assertRedirect(route('login'));
});

/*
|--------------------------------------------------------------------------
| El prospecto: primer catálogo con adjuntos propios
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * Un PDF chiquito pero real: el mime se deduce del CONTENIDO
 * (`getMimeType()`, no `getClientMimeType()`), así que un
 * `UploadedFile::fake()->create()` -que es un archivo vacío- daría
 * `application/x-empty` y la validación lo rechazaría.
 */
function prospectoDePrueba(string $nombre = 'prospecto.pdf'): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($ruta, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

    return new UploadedFile($ruta, $nombre, 'application/pdf', null, true);
}

it('sube el prospecto como adjunto colgado del medicamento', function (): void {
    $usuario = User::factory()->create();
    $medicamento = Medicamento::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->post(route('medicamentos.adjuntos.store', $medicamento), [
            'archivos' => [prospectoDePrueba()],
            'tipo' => TipoAdjunto::Prospecto->value,
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    expect($medicamento->adjuntos()->count())->toBe(1)
        ->and($medicamento->adjuntos()->first()->tipo)->toBe(TipoAdjunto::Prospecto);
});

it('el listado trae el prospecto ya con su URL de descarga', function (): void {
    $usuario = User::factory()->create();
    $medicamento = Medicamento::factory()->for($usuario, 'usuario')->create();
    $adjunto = $medicamento->adjuntos()->create([
        'tipo' => TipoAdjunto::Prospecto,
        'ruta' => 'medicamentos/1/prospecto.cif',
        'nombre_original' => 'prospecto.pdf',
        'mime' => 'application/pdf',
        'tamanio_bytes' => 2048,
    ]);

    $this->actingAs($usuario)
        ->get(route('medicamentos.index'))
        ->assertInertia(fn ($p) => $p
            ->where('registros.0.prospecto.nombre', 'prospecto.pdf')
            ->where('registros.0.prospecto.url', route('adjuntos.show', $adjunto))
        );
});

it('un medicamento sin prospecto lo trae en null', function (): void {
    $usuario = User::factory()->create();
    Medicamento::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->get(route('medicamentos.index'))
        ->assertInertia(fn ($p) => $p->where('registros.0.prospecto', null));
});

it('NO se le puede subir un prospecto a una semilla compartida', function (): void {
    // Sale gratis de la delegación en AdjuntoPolicy: subir es "update" del
    // dueño, y CatalogoPolicy::update() niega una semilla (regla 5).
    $semilla = Medicamento::factory()->semilla()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('medicamentos.adjuntos.store', $semilla), [
            'archivos' => [prospectoDePrueba()],
            'tipo' => TipoAdjunto::Prospecto->value,
        ])
        ->assertForbidden();

    expect($semilla->adjuntos()->count())->toBe(0);
});

it('un usuario ajeno no puede subirle un prospecto a mi medicamento', function (): void {
    $medicamento = Medicamento::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('medicamentos.adjuntos.store', $medicamento), [
            'archivos' => [prospectoDePrueba()],
            'tipo' => TipoAdjunto::Prospecto->value,
        ])
        ->assertForbidden();
});

it('el dueño puede borrar el prospecto por la ruta general de adjuntos', function (): void {
    $usuario = User::factory()->create();
    $medicamento = Medicamento::factory()->for($usuario, 'usuario')->create();
    $adjunto = $medicamento->adjuntos()->create([
        'tipo' => TipoAdjunto::Prospecto,
        'ruta' => 'medicamentos/1/prospecto.cif',
        'nombre_original' => 'prospecto.pdf',
        'mime' => 'application/pdf',
        'tamanio_bytes' => 2048,
    ]);

    $this->actingAs($usuario)
        ->delete(route('adjuntos.destroy', $adjunto))
        ->assertRedirect();

    expect($medicamento->adjuntos()->count())->toBe(0);
});
