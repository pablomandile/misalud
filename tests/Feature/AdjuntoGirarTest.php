<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Models\Adjunto;
use App\Models\Paciente;
use App\Models\User;
use App\Services\ArchivoService;
use App\Services\GiradorDeImagenes;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * Una imagen de 2×1: rojo a la izquierda, azul a la derecha. Alcanza para saber
 * hacia dónde giró: a la derecha (horario), el rojo queda ARRIBA.
 */
function imagenRojaYAzul(string $formato = 'png'): string
{
    $imagen = imagecreatetruecolor(2, 1);
    imagesetpixel($imagen, 0, 0, (int) imagecolorallocate($imagen, 255, 0, 0));
    imagesetpixel($imagen, 1, 0, (int) imagecolorallocate($imagen, 0, 0, 255));

    ob_start();
    $formato === 'png' ? imagepng($imagen) : imagejpeg($imagen, null, 100);

    return (string) ob_get_clean();
}

/**
 * @return array{0: User, 1: Paciente, 2: Adjunto}
 */
function fichaConFoto(string $contenido, string $nombre = 'caja.png'): array
{
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $datos = app(ArchivoService::class)->guardarContenido($contenido, $nombre, $paciente->carpetaDeArchivos());

    return [$usuario, $paciente, $paciente->adjuntos()->create($datos + ['tipo' => 'otro'])];
}

/**
 * El color del pixel, como 'rojo' o 'azul', para no comparar enteros de GD.
 */
function colorEn(string $contenido, int $x, int $y): string
{
    $imagen = imagecreatefromstring($contenido);
    $rgb = imagecolorat($imagen, $x, $y);

    return (($rgb >> 16) & 0xFF) > (($rgb & 0xFF)) ? 'rojo' : 'azul';
}

it('gira la foto a la derecha y queda guardada así', function (): void {
    [$usuario, , $adjunto] = fichaConFoto(imagenRojaYAzul());

    $this->actingAs($usuario)
        ->put(route('adjuntos.girar', $adjunto), ['sentido' => 'derecha'])
        ->assertRedirect()
        ->assertSessionHas('exito');

    $girada = app(ArchivoService::class)->contenido($adjunto->refresh());
    $imagen = imagecreatefromstring($girada);

    expect(imagesx($imagen))->toBe(1)
        ->and(imagesy($imagen))->toBe(2)
        ->and(colorEn($girada, 0, 0))->toBe('rojo')
        ->and(colorEn($girada, 0, 1))->toBe('azul')
        ->and($adjunto->tamanio_bytes)->toBe(strlen($girada));
});

it('a la izquierda, el rojo queda abajo', function (): void {
    [$usuario, , $adjunto] = fichaConFoto(imagenRojaYAzul());

    $this->actingAs($usuario)->put(route('adjuntos.girar', $adjunto), ['sentido' => 'izquierda']);

    $girada = app(ArchivoService::class)->contenido($adjunto->refresh());

    expect(colorEn($girada, 0, 0))->toBe('azul')
        ->and(colorEn($girada, 0, 1))->toBe('rojo');
});

it('escribe el archivo en una ruta nueva, borra el viejo y cambia la versión de la URL', function (): void {
    [$usuario, , $adjunto] = fichaConFoto(imagenRojaYAzul());
    $rutaVieja = $adjunto->ruta;
    $versionVieja = $adjunto->version();

    $this->actingAs($usuario)->put(route('adjuntos.girar', $adjunto), ['sentido' => 'derecha']);
    $adjunto->refresh();

    Storage::disk('local')->assertMissing($rutaVieja);
    Storage::disk('local')->assertExists($adjunto->ruta);

    expect($adjunto->ruta)->not->toBe($rutaVieja)
        ->and($adjunto->version())->not->toBe($versionVieja)
        // Sigue cifrado en disco: no es un PNG legible.
        ->and(Storage::disk('local')->get($adjunto->ruta))->not->toContain('PNG');
});

it('gira un JPEG', function (): void {
    [$usuario, , $adjunto] = fichaConFoto(imagenRojaYAzul('jpeg'), 'caja.jpg');

    $this->actingAs($usuario)->put(route('adjuntos.girar', $adjunto), ['sentido' => 'derecha']);

    $girada = app(ArchivoService::class)->contenido($adjunto->refresh());

    expect($adjunto->mime)->toBe('image/jpeg')
        ->and(imagesx(imagecreatefromstring($girada)))->toBe(1);
});

it('un PDF no se gira y no se toca', function (): void {
    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    [$usuario, , $adjunto] = fichaConFoto($pdf, 'orden.pdf');
    $ruta = $adjunto->ruta;

    $this->actingAs($usuario)
        ->put(route('adjuntos.girar', $adjunto), ['sentido' => 'derecha'])
        ->assertSessionHas('error');

    expect($adjunto->refresh()->ruta)->toBe($ruta)
        ->and(app(ArchivoService::class)->contenido($adjunto))->toBe($pdf);
});

it('un lector no puede girar, y se le contesta 403 antes de validar', function (): void {
    [, $paciente, $adjunto] = fichaConFoto(imagenRojaYAzul());
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->put(route('adjuntos.girar', $adjunto), ['sentido' => 'cualquiera'])
        ->assertForbidden();
});

it('un cuidador sí puede girar', function (): void {
    [, $paciente, $adjunto] = fichaConFoto(imagenRojaYAzul());
    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->actingAs($cuidador)
        ->put(route('adjuntos.girar', $adjunto), ['sentido' => 'derecha'])
        ->assertSessionHas('exito');
});

it('el sentido es obligatorio', function (): void {
    [$usuario, , $adjunto] = fichaConFoto(imagenRojaYAzul());

    $this->actingAs($usuario)
        ->put(route('adjuntos.girar', $adjunto), ['sentido' => 'arriba'])
        ->assertSessionHasErrors('sentido');
});

it('la lista dice qué se puede girar', function (): void {
    expect(app(GiradorDeImagenes::class)->admite('image/jpeg'))->toBeTrue()
        ->and(app(GiradorDeImagenes::class)->admite('image/heic'))->toBeFalse()
        ->and(app(GiradorDeImagenes::class)->admite('application/pdf'))->toBeFalse();
});
