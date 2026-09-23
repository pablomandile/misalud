<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Enums\TipoAdjunto;
use App\Models\Adjunto;
use App\Models\Paciente;
use App\Models\User;
use App\Services\ArchivoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * Un PDF chiquito pero real: el service deduce el mime del CONTENIDO, así que
 * un `UploadedFile::fake()->create('x.pdf')` -que es un archivo vacío- daría
 * `application/x-empty` y sería rechazado.
 */
function pdfDePrueba(string $nombre = 'analisis.pdf'): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($ruta, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

    return new UploadedFile($ruta, $nombre, 'application/pdf', null, true);
}

function adjuntoDe(Paciente $paciente, array $atributos = []): Adjunto
{
    return $paciente->adjuntos()->create(
        array_merge(Adjunto::factory()->definition(), $atributos),
    );
}

/*
|--------------------------------------------------------------------------
| El archivo en el disco
|--------------------------------------------------------------------------
*/

it('guarda el archivo cifrado: el contenido original NO está en el disco', function (): void {
    $datos = app(ArchivoService::class)->guardar(pdfDePrueba(), 'adjuntos');

    $enDisco = Storage::disk('local')->get($datos['ruta']);

    expect($enDisco)->not->toContain('%PDF-1.4')
        ->and($datos['ruta'])->toEndWith('.cif')
        ->and($datos['mime'])->toBe('application/pdf')
        ->and($datos['nombre_original'])->toBe('analisis.pdf');
});

it('devuelve el contenido original al descifrar', function (): void {
    $servicio = app(ArchivoService::class);
    $datos = $servicio->guardar(pdfDePrueba(), 'adjuntos');

    $adjunto = new Adjunto($datos);

    expect($servicio->contenido($adjunto))->toContain('%PDF-1.4');
});

it('guarda el tamaño del original y no el del cifrado', function (): void {
    $servicio = app(ArchivoService::class);
    $datos = $servicio->guardar(pdfDePrueba(), 'adjuntos');

    $cifrado = strlen((string) Storage::disk('local')->get($datos['ruta']));

    expect($datos['tamanio_bytes'])->toBeLessThan($cifrado);
});

it('no guarda el nombre original en la ruta del disco', function (): void {
    $datos = app(ArchivoService::class)->guardar(pdfDePrueba('juan-perez-hiv.pdf'), 'adjuntos');

    expect($datos['ruta'])->not->toContain('juan')
        ->and($datos['ruta'])->not->toContain('hiv');
});

it('rechaza un tipo de archivo que no está en la lista blanca', function (): void {
    $ruta = tempnam(sys_get_temp_dir(), 'html');
    file_put_contents($ruta, '<html><script>alert(1)</script></html>');

    // Se declara como PDF desde el cliente: lo que manda es el contenido real.
    $archivo = new UploadedFile($ruta, 'inocente.pdf', 'application/pdf', null, true);

    expect(fn () => app(ArchivoService::class)->guardar($archivo, 'adjuntos'))
        ->toThrow(RuntimeException::class);

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('rechaza un archivo más grande que el máximo', function (): void {
    $archivo = UploadedFile::fake()->create('grande.pdf', (ArchivoService::MAXIMO_BYTES / 1024) + 100);

    expect(fn () => app(ArchivoService::class)->guardar($archivo, 'adjuntos'))
        ->toThrow(RuntimeException::class);
});

it('limpia el nombre original de barras y comillas', function (): void {
    $datos = app(ArchivoService::class)->guardar(
        pdfDePrueba('../../etc/pas"swd.pdf'),
        'adjuntos',
    );

    expect($datos['nombre_original'])->toBe('passwd.pdf');
});

/*
|--------------------------------------------------------------------------
| Quién puede ver el archivo
|--------------------------------------------------------------------------
*/

it('el propietario ve su archivo, con el mime correcto', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    $datos = app(ArchivoService::class)->guardar(pdfDePrueba(), 'adjuntos');
    $adjunto = adjuntoDe($paciente, $datos + ['tipo' => TipoAdjunto::InformeEstudio]);

    $this->actingAs($usuario)
        ->get(route('adjuntos.show', $adjunto))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('un usuario ajeno NO puede ver el archivo por la URL directa', function (): void {
    $paciente = Paciente::factory()->create();
    $adjunto = adjuntoDe($paciente);

    $this->actingAs(User::factory()->create())
        ->get(route('adjuntos.show', $adjunto))
        ->assertForbidden();
});

it('un lector puede ver el archivo pero NO borrarlo', function (): void {
    $paciente = Paciente::factory()->create();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $datos = app(ArchivoService::class)->guardar(pdfDePrueba(), 'adjuntos');
    $adjunto = adjuntoDe($paciente, $datos);

    $this->actingAs($lector)->get(route('adjuntos.show', $adjunto))->assertOk();
    $this->actingAs($lector)->delete(route('adjuntos.destroy', $adjunto))->assertForbidden();

    expect(Adjunto::find($adjunto->id))->not->toBeNull();
});

it('un cuidador sí puede borrarlo', function (): void {
    $paciente = Paciente::factory()->create();
    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $datos = app(ArchivoService::class)->guardar(pdfDePrueba(), 'adjuntos');
    $adjunto = adjuntoDe($paciente, $datos);

    $this->actingAs($cuidador)
        ->delete(route('adjuntos.destroy', $adjunto))
        ->assertRedirect();

    expect(Adjunto::find($adjunto->id))->toBeNull()
        ->and(Storage::disk('local')->exists($datos['ruta']))->toBeFalse();
});

it('exige sesión para ver un archivo', function (): void {
    $adjunto = adjuntoDe(Paciente::factory()->create());

    $this->get(route('adjuntos.show', $adjunto))->assertRedirect(route('login'));
});

/*
|--------------------------------------------------------------------------
| La cadena hacia el paciente
|--------------------------------------------------------------------------
*/

it('niega el acceso si el adjunto no llega a ningún paciente', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $adjunto = adjuntoDe($paciente);

    // Se rompe la cadena: el dueño apunta a algo que no existe.
    $adjunto->forceFill(['adjuntable_id' => 999999])->save();

    expect($adjunto->fresh()->pacienteDelRegistro())->toBeNull();

    $this->actingAs($usuario)
        ->get(route('adjuntos.show', $adjunto->id))
        ->assertForbidden();
});

it('no expone adjuntable_type ni adjuntable_id a la asignación masiva', function (): void {
    $adjunto = new Adjunto([
        'adjuntable_type' => Paciente::class,
        'adjuntable_id' => 1,
        'tipo' => TipoAdjunto::Otro,
    ]);

    expect($adjunto->adjuntable_type)->toBeNull()
        ->and($adjunto->adjuntable_id)->toBeNull();
});

it('cifra el nombre original en la base', function (): void {
    $paciente = Paciente::factory()->create();
    $adjunto = adjuntoDe($paciente, ['nombre_original' => 'resultado-vih.pdf']);

    $crudo = DB::table('adjuntos')->where('id', $adjunto->id)->value('nombre_original');

    expect($crudo)->not->toContain('vih')
        ->and($adjunto->fresh()->nombre_original)->toBe('resultado-vih.pdf');
});
