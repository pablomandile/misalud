<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\ModeloCifradoMysql;

/*
 * Sonda del cifrado contra el motor real.
 *
 * El resto de la suite corre sobre sqlite en memoria. Sqlite tolera cosas que
 * MySQL rechaza —no valida ENUM, no respeta longitudes de columna, no tiene
 * modo estricto—, así que un esquema puede pasar entera la suite y reventar en
 * producción al primer guardado. Estas pruebas van contra MySQL de verdad.
 *
 * Si no hay MySQL a mano, se saltean: no todo el mundo lo tiene levantado, y el
 * CI sí lo levanta.
 */
beforeEach(function (): void {
    /*
     * `phpunit.xml` fija DB_DATABASE=:memory: para que la suite corra en sqlite,
     * y eso pisa también el nombre de base de la conexión MySQL, que queda
     * apuntando a una base llamada ":memory:". Hay que devolvérselo antes de
     * conectar, o la sonda se saltea siempre creyendo que no hay MySQL.
     *
     * El resto (host, usuario, contraseña) sí llega bien desde el .env.
     */
    config([
        'database.connections.mysql.url' => null,
        'database.connections.mysql.database' => 'misalud',
    ]);

    DB::purge('mysql');

    try {
        DB::connection('mysql')->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('MySQL no está disponible: '.$e->getMessage());
    }

    $esquema = Schema::connection('mysql');

    $esquema->dropIfExists('sonda_cifrado_mysql');

    $esquema->create('sonda_cifrado_mysql', function (Blueprint $tabla): void {
        $tabla->id();
        $tabla->text('nombre')->nullable();
        $tabla->text('notas')->nullable();
        $tabla->char('nombre_hash', 64)->nullable()->unique();
    });
});

afterEach(function (): void {
    try {
        Schema::connection('mysql')->dropIfExists('sonda_cifrado_mysql');
    } catch (Throwable) {
        // La base no estaba disponible; el test ya se salteó.
    }
});

it('guarda y recupera texto con acentos y eñes', function (): void {
    $fila = ModeloCifradoMysql::create([
        'nombre' => 'Dra. María Ángeles Peña',
        'notas' => 'Añadir control en septiembre — ¿glucemia?',
    ]);

    $leida = ModeloCifradoMysql::find($fila->id);

    expect($leida->nombre)->toBe('Dra. María Ángeles Peña')
        ->and($leida->notas)->toBe('Añadir control en septiembre — ¿glucemia?');
});

it('lo que queda en la base es ilegible', function (): void {
    $fila = ModeloCifradoMysql::create(['notas' => 'diagnóstico de hipertensión']);

    $crudo = (string) DB::connection('mysql')
        ->table('sonda_cifrado_mysql')
        ->where('id', $fila->id)
        ->value('notas');

    expect($crudo)
        ->not->toContain('hipertensión')
        ->not->toContain('diagn');
});

it('el UNIQUE sobre char(64) frena el duplicado', function (): void {
    ModeloCifradoMysql::create(['nombre' => 'Hospital Italiano']);

    expect(fn () => ModeloCifradoMysql::create(['nombre' => 'hospital  ITALIANO ']))
        ->toThrow(QueryException::class);
});

/*
 * La razón por la que las columnas cifradas van en `text` y no en `varchar`.
 * En sqlite esto pasaría sin chistar; MySQL en modo estricto lo corta.
 */
it('el payload cifrado no entra en un varchar(255)', function (): void {
    $texto = str_repeat('a', 200);
    $cifrado = ModeloCifradoMysql::create(['notas' => $texto]);

    $largoCifrado = strlen((string) DB::connection('mysql')
        ->table('sonda_cifrado_mysql')
        ->where('id', $cifrado->id)
        ->value('notas'));

    expect($largoCifrado)->toBeGreaterThan(255);

    Schema::connection('mysql')->create('sonda_varchar', function (Blueprint $tabla): void {
        $tabla->id();
        $tabla->string('notas', 255)->nullable();
    });

    expect(fn () => DB::connection('mysql')
        ->table('sonda_varchar')
        ->insert(['notas' => str_repeat('x', $largoCifrado)]))
        ->toThrow(QueryException::class);

    Schema::connection('mysql')->dropIfExists('sonda_varchar');
});

it('una nota larga entra en text sin truncarse', function (): void {
    // Una evolución clínica larga: ~8 KB de texto.
    $larga = str_repeat('Control mensual sin novedades. ', 270);

    $fila = ModeloCifradoMysql::create(['notas' => $larga]);

    expect(ModeloCifradoMysql::find($fila->id)->notas)->toBe($larga);
});

it('el builder vigilado también rige sobre MySQL', function (): void {
    expect(fn () => ModeloCifradoMysql::where('notas', 'algo')->first())
        ->toThrow(RuntimeException::class, 'es una columna cifrada');
});
