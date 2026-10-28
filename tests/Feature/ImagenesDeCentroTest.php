<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Enums\TipoAdjunto;
use App\Models\Centro;
use App\Models\Paciente;
use App\Models\User;
use App\Services\ArchivoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Las imágenes de un centro: el edificio, el QR de WhatsApp
|--------------------------------------------------------------------------
|
| Segundo catálogo con archivos, después del prospecto de un medicamento. Subir
| una imagen es editar el centro: la decide `CatalogoPolicy::update()`.
*/

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * Un PNG real: el mime se deduce del CONTENIDO, y un `UploadedFile::fake()`
 * vacío daría `application/x-empty`.
 */
function imagenDeCentro(string $nombre = 'qr.png'): UploadedFile
{
    $lienzo = imagecreatetruecolor(4, 4);
    $ruta = tempnam(sys_get_temp_dir(), 'png');
    imagepng($lienzo, $ruta);

    return new UploadedFile($ruta, $nombre, 'image/png', null, true);
}

/**
 * @return array<string, mixed>
 */
function subidaDeImagen(string $titulo = 'QR de WhatsApp'): array
{
    return [
        'archivos' => [imagenDeCentro()],
        'tipo' => TipoAdjunto::ImagenCentro->value,
        'titulo' => $titulo,
    ];
}

it('sube una imagen al centro, con su nombre, y la guarda cifrada', function (): void {
    $usuario = User::factory()->create();
    $centro = Centro::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->post(route('centros.adjuntos.store', $centro), subidaDeImagen())
        ->assertRedirect()
        ->assertSessionHas('exito');

    $imagen = $centro->adjuntos()->sole();

    expect($imagen->tipo)->toBe(TipoAdjunto::ImagenCentro)
        ->and($imagen->titulo)->toBe('QR de WhatsApp')
        ->and($imagen->ruta)->toStartWith("centros/{$centro->id}/")
        ->and(Storage::disk('local')->get($imagen->ruta))->not->toStartWith("\x89PNG")
        ->and(app(ArchivoService::class)->contenido($imagen))->toStartWith("\x89PNG");
});

it('el listado trae las imágenes de cada centro con su URL', function (): void {
    $usuario = User::factory()->create();
    $centro = Centro::factory()->for($usuario, 'usuario')->create();
    $this->actingAs($usuario)->post(route('centros.adjuntos.store', $centro), subidaDeImagen('Frente'));

    $this->actingAs($usuario)
        ->get(route('centros.index'))
        ->assertInertia(fn ($p) => $p
            ->has('registros.0.imagenes', 1)
            ->where('registros.0.imagenes.0.titulo', 'Frente')
            ->where('registros.0.imagenes.0.mime', 'image/png'));
});

it('una semilla no admite imágenes, y un extraño no puede subirle a mi centro', function (): void {
    $semilla = Centro::factory()->semilla()->create();
    $ajeno = Centro::factory()->create();
    $extranio = User::factory()->create();

    $this->actingAs($extranio)->post(route('centros.adjuntos.store', $semilla), subidaDeImagen())->assertForbidden();
    $this->actingAs($extranio)->post(route('centros.adjuntos.store', $ajeno), subidaDeImagen())->assertForbidden();

    expect($semilla->adjuntos()->count() + $ajeno->adjuntos()->count())->toBe(0);
});

it('el cuidador sube imágenes al centro del dueño; el lector no', function (): void {
    $duenio = User::factory()->create();
    $cuidador = User::factory()->create();
    $lector = User::factory()->create();
    $ficha = Paciente::factory()->for($duenio, 'usuario')->create();
    $ficha->cuidadores()->attach($cuidador->id, ['rol' => RolPaciente::Cuidador->value]);
    $ficha->cuidadores()->attach($lector->id, ['rol' => RolPaciente::Lector->value]);
    $centro = Centro::factory()->for($duenio, 'usuario')->create();

    $this->actingAs($lector)->post(route('centros.adjuntos.store', $centro), subidaDeImagen())->assertForbidden();
    $this->actingAs($cuidador)->post(route('centros.adjuntos.store', $centro), subidaDeImagen())->assertSessionHas('exito');

    expect($centro->adjuntos()->count())->toBe(1);
});

it('borrar el centro se lleva sus imágenes del disco', function (): void {
    $usuario = User::factory()->create();
    $centro = Centro::factory()->for($usuario, 'usuario')->create();
    $this->actingAs($usuario)->post(route('centros.adjuntos.store', $centro), subidaDeImagen());
    $ruta = $centro->adjuntos()->sole()->ruta;

    $this->actingAs($usuario)->delete(route('centros.destroy', $centro))->assertSessionHas('exito');

    Storage::disk('local')->assertMissing($ruta);
    expect(Centro::withTrashed()->find($centro->id))->toBeNull();
});

it('copiar un centro no se lleva sus imágenes', function (): void {
    $duenio = User::factory()->create();
    $cuidador = User::factory()->create();
    $ficha = Paciente::factory()->for($duenio, 'usuario')->create();
    $ficha->cuidadores()->attach($cuidador->id, ['rol' => RolPaciente::Lector->value]);
    $centro = Centro::factory()->for($duenio, 'usuario')->create();
    $this->actingAs($duenio)->post(route('centros.adjuntos.store', $centro), subidaDeImagen());

    $this->actingAs($cuidador)->post(route('centros.duplicar', $centro))->assertSessionHas('exito');

    $copia = Centro::where('usuario_id', $cuidador->id)->sole();
    expect($copia->adjuntos()->count())->toBe(0);
});
