<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
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

/** El valor tal cual quedó guardado, sin pasar por los casts de Eloquent. */
function crudo(int $id, string $columna): ?string
{
    return DB::table('modelos_cifrados')->where('id', $id)->value($columna);
}

it('guarda el valor cifrado y lo devuelve en claro', function (): void {
    $fila = ModeloCifrado::create(['nombre' => 'Dra. Ana Gómez']);

    expect(crudo($fila->id, 'nombre'))
        ->not->toBeNull()
        ->not->toContain('Gómez');

    expect(ModeloCifrado::find($fila->id)->nombre)->toBe('Dra. Ana Gómez');
});

/*
 * Este test es el que justifica todo el mecanismo del índice ciego. Si algún
 * día falla —porque alguien cambió el cifrado por uno determinístico— entonces
 * las columnas de hash sobran y hay que revisar el diseño entero.
 */
it('cifra el mismo texto distinto cada vez, así que un UNIQUE sobre la columna cifrada no ve el duplicado', function (): void {
    $uno = ModeloCifrado::create(['notas' => 'hipertensión']);
    $dos = ModeloCifrado::create(['notas' => 'hipertensión']);

    expect(crudo($uno->id, 'notas'))->not->toBe(crudo($dos->id, 'notas'));

    // Y por eso mismo tampoco se puede buscar por igualdad sobre la columna.
    expect(DB::table('modelos_cifrados')->where('notas', 'hipertensión')->count())->toBe(0);
});

it('el índice ciego sí es igual para el mismo texto, y el UNIQUE lo frena', function (): void {
    ModeloCifrado::create(['nombre' => 'Hospital Italiano']);

    expect(fn () => ModeloCifrado::create(['nombre' => 'Hospital Italiano']))
        ->toThrow(QueryException::class);
});

it('normaliza mayúsculas y espacios antes de hashear', function (): void {
    expect(ModeloCifrado::hashCiego('  Hospital   Italiano '))
        ->toBe(ModeloCifrado::hashCiego('hospital italiano'));
});

/*
 * Sacar los acentos haría que "José" y "Jose" se reconozcan como el mismo
 * médico, pero también haría chocar dos apellidos distintos. El índice ciego
 * responde "¿es exactamente este?"; lo difuso va en PHP.
 */
it('conserva los acentos: Peña y Pena no son la misma persona', function (): void {
    expect(ModeloCifrado::hashCiego('Peña'))
        ->not->toBe(ModeloCifrado::hashCiego('Pena'));
});

it('deja el hash en null cuando el campo de origen está vacío', function (): void {
    $fila = ModeloCifrado::create(['notas' => 'algo']);

    expect($fila->nombre_hash)->toBeNull();
});

it('encuentra por el índice ciego sin tocar la columna cifrada', function (): void {
    $fila = ModeloCifrado::create(['nombre' => 'Dr. Pérez']);

    expect(ModeloCifrado::dondeIndiceCiego('nombre', 'dr.  PÉREZ')->first()?->id)
        ->toBe($fila->id);
});

it('avisa si se consulta un índice ciego que el modelo no declara', function (): void {
    expect(fn () => ModeloCifrado::dondeIndiceCiego('notas', 'x')->first())
        ->toThrow(RuntimeException::class, 'no declara un índice ciego');
});

/*
 * Es lo que obliga a que `misalud:recifrar` recalcule los hashes además de
 * reescribir los valores cifrados: si solo recifrara, los índices quedarían
 * calculados con la clave vieja y ningún UNIQUE volvería a coincidir.
 */
it('el hash cambia si cambia APP_KEY', function (): void {
    $conLaVieja = ModeloCifrado::hashCiego('Hospital Italiano');

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    expect(ModeloCifrado::hashCiego('Hospital Italiano'))->not->toBe($conLaVieja);
});

/*
 * La clave del HMAC se deriva de APP_KEY con HKDF, no es APP_KEY. Usar la misma
 * clave para cifrar y para indexar mezcla dos propósitos.
 */
it('no usa APP_KEY directo como clave del HMAC', function (): void {
    $clave = base64_decode(substr((string) config('app.key'), 7), true);

    expect(ModeloCifrado::hashCiego('x'))
        ->not->toBe(hash_hmac('sha256', 'x', (string) $clave));
});

/*
 * Estas dos consultas son el modo de falla silencioso del cifrado: compilan,
 * corren y devuelven cero filas o un orden arbitrario, sin error. El builder
 * vigilado las convierte en una excepción ruidosa.
 */
it('no deja filtrar por una columna cifrada', function (): void {
    expect(fn () => ModeloCifrado::where('nombre', 'Dr. Pérez')->first())
        ->toThrow(RuntimeException::class, 'es una columna cifrada');
});

it('no deja ordenar por una columna cifrada', function (): void {
    expect(fn () => ModeloCifrado::orderBy('nombre')->get())
        ->toThrow(RuntimeException::class, 'es una columna cifrada');
});

it('tampoco si la columna viene calificada con la tabla', function (): void {
    expect(fn () => ModeloCifrado::where('modelos_cifrados.nombre', 'x')->first())
        ->toThrow(RuntimeException::class, 'es una columna cifrada');
});

it('deja en paz las columnas que no están cifradas', function (): void {
    $fila = ModeloCifrado::create(['nombre' => 'Dr. Pérez']);

    expect(ModeloCifrado::where('id', $fila->id)->orderBy('id')->first()?->nombre)
        ->toBe('Dr. Pérez');
});
