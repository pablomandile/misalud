<?php

declare(strict_types=1);

use App\Enums\TipoAdjunto;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\CatalogoDePrueba;

/*
|--------------------------------------------------------------------------
| Un adjunto colgado de un CATÁLOGO, que no pertenece a ningún paciente
|--------------------------------------------------------------------------
|
| Es el caso que la vieja autorización -la que subía por `adjuntable` hasta
| encontrar un paciente- negaba siempre, porque un catálogo es del usuario y
| ahí esa cadena devolvía `null`. El primero real es el prospecto de un
| medicamento (paso 5.3); acá se prueba con un catálogo de juguete para que la
| rama no llegue sin cubrir al commit que la necesita.
|
*/

beforeEach(function (): void {
    Schema::create('catalogos_de_prueba', function (Blueprint $tabla): void {
        $tabla->id();
        $tabla->foreignId('usuario_id')->nullable();
        $tabla->text('nombre')->nullable();
        $tabla->char('nombre_hash', 64)->nullable();
    });
});

function adjuntoDeCatalogo(CatalogoDePrueba $catalogo): object
{
    return $catalogo->adjuntos()->create([
        'tipo' => TipoAdjunto::Prospecto,
        'ruta' => 'catalogos/1/prospecto.cif',
        'nombre_original' => 'prospecto.pdf',
        'mime' => 'application/pdf',
        'tamanio_bytes' => 1024,
    ]);
}

it('el dueño del catálogo PUEDE ver el archivo colgado de él', function (): void {
    $usuario = User::factory()->create();
    $catalogo = CatalogoDePrueba::create([
        'usuario_id' => $usuario->id,
        'nombre' => 'Ibuprofeno',
    ]);
    $adjunto = adjuntoDeCatalogo($catalogo);

    expect(Gate::forUser($usuario)->allows('view', $adjunto))->toBeTrue()
        ->and(Gate::forUser($usuario)->allows('delete', $adjunto))->toBeTrue();
});

it('un usuario ajeno NO puede ver el archivo de un catálogo que no es suyo', function (): void {
    $duenio = User::factory()->create();
    $catalogo = CatalogoDePrueba::create([
        'usuario_id' => $duenio->id,
        'nombre' => 'Ibuprofeno',
    ]);
    $adjunto = adjuntoDeCatalogo($catalogo);

    expect(Gate::forUser(User::factory()->create())->allows('view', $adjunto))->toBeFalse();
});

it('una semilla compartida se VE pero no se le puede colgar ni sacar un archivo', function (): void {
    /*
     * Sale gratis de delegar: `CatalogoPolicy::view()` deja ver una semilla y
     * su `update()` la niega, así que el archivo de una semilla se lee y no se
     * toca. Para cambiarlo hay que duplicar la semilla primero, que es
     * exactamente lo que corresponde (regla 5).
     */
    $semilla = CatalogoDePrueba::create(['usuario_id' => null, 'nombre' => 'Ibuprofeno']);
    $adjunto = adjuntoDeCatalogo($semilla);
    $cualquiera = User::factory()->create();

    expect(Gate::forUser($cualquiera)->allows('view', $adjunto))->toBeTrue()
        ->and(Gate::forUser($cualquiera)->allows('update', $adjunto))->toBeFalse()
        ->and(Gate::forUser($cualquiera)->allows('delete', $adjunto))->toBeFalse();
});

it('el archivo de un catálogo se sirve por la misma ruta de siempre', function (): void {
    // La delegación no obliga a rutas nuevas: el controlador ya pregunta por
    // `view` del adjunto, y quien responde cambia solo.
    $usuario = User::factory()->create();
    $catalogo = CatalogoDePrueba::create([
        'usuario_id' => $usuario->id,
        'nombre' => 'Ibuprofeno',
    ]);
    $adjunto = adjuntoDeCatalogo($catalogo);

    $this->actingAs(User::factory()->create())
        ->get(route('adjuntos.show', $adjunto))
        ->assertForbidden();
});
