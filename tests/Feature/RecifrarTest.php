<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\ModeloCifrado;

beforeEach(function (): void {
    Schema::create('modelos_cifrados', function (Blueprint $tabla): void {
        $tabla->id();
        $tabla->text('nombre')->nullable();
        $tabla->text('notas')->nullable();
        $tabla->char('nombre_hash', 64)->nullable()->unique();
    });
});

/**
 * Cambia la APP_KEY en caliente, como haría `key:generate` en el .env.
 *
 * El encrypter está registrado como singleton y ya quedó resuelto con la clave
 * vieja, así que no alcanza con tocar la config: hay que volver a registrarlo.
 *
 * @param  list<string>  $previas
 */
function rotarClave(string $nueva, array $previas = []): void
{
    config(['app.key' => $nueva, 'app.previous_keys' => $previas]);

    app()->forgetInstance('encrypter');
    (new EncryptionServiceProvider(app()))->register();
    Crypt::clearResolvedInstances();
}

function claveNueva(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

/** @return array{nombre: string, nombre_hash: string} */
function filaCruda(int $id): array
{
    /** @var object{nombre: string, nombre_hash: string} $fila */
    $fila = DB::table('modelos_cifrados')->where('id', $id)->first();

    return ['nombre' => $fila->nombre, 'nombre_hash' => $fila->nombre_hash];
}

it('recifra los datos y recalcula los índices con la clave nueva', function (): void {
    $vieja = (string) config('app.key');

    $fila = ModeloCifrado::create(['nombre' => 'Hospital Italiano', 'notas' => 'control anual']);
    $antes = filaCruda($fila->id);

    // Rotación: clave nueva, la vieja pasa a las previas.
    rotarClave(claveNueva(), [$vieja]);

    $this->artisan('misalud:recifrar', ['--modelo' => [ModeloCifrado::class]])
        ->assertSuccessful();

    $despues = filaCruda($fila->id);

    expect($despues['nombre'])->not->toBe($antes['nombre'])
        ->and($despues['nombre_hash'])->not->toBe($antes['nombre_hash'])
        ->and($despues['nombre_hash'])->toBe(ModeloCifrado::hashCiego('Hospital Italiano'));

    /*
     * La prueba de fuego: sacar la clave vieja de las previas. Si el recifrado
     * no hubiera reescrito de verdad, acá el valor dejaría de poder leerse.
     */
    rotarClave((string) config('app.key'), []);

    $recargada = ModeloCifrado::find($fila->id);

    expect($recargada->nombre)->toBe('Hospital Italiano')
        ->and($recargada->notas)->toBe('control anual');
});

it('en modo seco no toca nada', function (): void {
    $fila = ModeloCifrado::create(['nombre' => 'Hospital Italiano']);
    $antes = filaCruda($fila->id);

    rotarClave(claveNueva(), [(string) config('app.key')]);

    $this->artisan('misalud:recifrar', [
        '--modelo' => [ModeloCifrado::class],
        '--seco' => true,
    ])->assertSuccessful();

    expect(filaCruda($fila->id))->toBe($antes);
});

it('falla y avisa si la clave vieja no está en APP_PREVIOUS_KEYS', function (): void {
    ModeloCifrado::create(['nombre' => 'Hospital Italiano']);

    // Se rota sin declarar la clave anterior: los datos quedan ilegibles.
    rotarClave(claveNueva(), []);

    $this->artisan('misalud:recifrar', ['--modelo' => [ModeloCifrado::class]])
        ->expectsOutputToContain('no se pudo descifrar')
        ->assertFailed();
});

it('ignora una clase que no es un modelo', function (): void {
    $this->artisan('misalud:recifrar', ['--modelo' => ['App\\Http\\Controllers\\Controller']])
        ->expectsOutputToContain('no es un modelo Eloquent')
        ->assertSuccessful();
});
