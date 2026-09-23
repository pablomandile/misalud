<?php

declare(strict_types=1);

use App\Contracts\CifraDatos;
use App\Database\Eloquent\ConsultaVigilada;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Fixtures\ModeloMalCifrado;

/**
 * Revisa el esquema real de un modelo contra las reglas del cifrado.
 *
 * @return list<string> los problemas encontrados, vacío si está todo bien
 */
function problemasDeCifrado(Model&CifraDatos $modelo): array
{
    $tabla = $modelo->getTable();
    $problemas = [];

    /** @var list<string> $cifradas */
    $cifradas = $modelo->camposCifrados();
    $indices = $modelo->indicesCiegos();

    $columnas = collect(Schema::getColumns($tabla))->keyBy('name');

    foreach (Schema::getIndexes($tabla) as $indice) {
        foreach ($indice['columns'] as $columna) {
            if (in_array($columna, $cifradas, true)) {
                $problemas[] = sprintf(
                    'hay un índice (%s) sobre la columna cifrada [%s.%s]: el ciphertext cambia en cada '
                    .'guardado, así que ese índice no puede encontrar ni impedir nada. Va sobre su '
                    .'columna de hash.',
                    $indice['name'],
                    $tabla,
                    $columna,
                );
            }
        }
    }

    foreach ($cifradas as $columna) {
        $tipo = $columnas->get($columna)['type_name'] ?? null;

        if ($tipo === null) {
            $problemas[] = "la columna cifrada [{$tabla}.{$columna}] no existe en la base.";

            continue;
        }

        if (! Str::contains($tipo, ['text', 'blob'])) {
            $problemas[] = sprintf(
                'la columna cifrada [%s.%s] es %s: el payload cifrado ocupa ~190 bytes fijos más '
                    .'~1.8× el original, así que va en `text`.',
                $tabla,
                $columna,
                $tipo,
            );
        }
    }

    foreach ($indices as $origen => $columnaHash) {
        if (! $columnas->has($columnaHash)) {
            $problemas[] = "el índice ciego de [{$origen}] apunta a [{$tabla}.{$columnaHash}], que no existe.";

            continue;
        }

        $indexada = collect(Schema::getIndexes($tabla))
            ->contains(fn (array $i): bool => in_array($columnaHash, $i['columns'], true));

        if (! $indexada) {
            $problemas[] = "la columna de hash [{$tabla}.{$columnaHash}] no tiene índice: "
                .'sin índice no sirve para lo único que existe, que es buscar y garantizar unicidad.';
        }
    }

    /*
     * Sin el builder vigilado, un `where` sobre una columna cifrada vuelve a ser
     * silencioso. No puede declararlo el trait: PHP no deja que un trait pise
     * una propiedad heredada con otro valor, así que va modelo por modelo — y
     * por eso hace falta que algo verifique que nadie se lo olvidó.
     */
    if (! $modelo->newQuery() instanceof ConsultaVigilada) {
        $problemas[] = 'no declara [protected static $builder = ConsultaVigilada::class], así que '
            .'un where u orderBy sobre una columna cifrada devolvería cero filas sin avisar.';
    }

    return $problemas;
}

/** @return list<class-string<Model&CifraDatos>> */
function modelosQueCifran(): array
{
    $clases = [];

    foreach (glob(app_path('Models/*.php')) ?: [] as $ruta) {
        $clase = 'App\\Models\\'.Str::before(basename($ruta), '.php');

        if (class_exists($clase)
            && is_subclass_of($clase, Model::class)
            && is_a($clase, CifraDatos::class, true)) {
            $clases[] = $clase;
        }
    }

    return $clases;
}

it('no tiene ningún modelo con un índice sobre una columna cifrada', function (): void {
    $problemas = [];

    foreach (modelosQueCifran() as $clase) {
        foreach (problemasDeCifrado(new $clase) as $problema) {
            $problemas[] = class_basename($clase).': '.$problema;
        }
    }

    expect($problemas)->toBe([], "\n - ".implode("\n - ", $problemas)."\n");
});

/*
 * Mientras no haya modelos de dominio, el test de arriba no revisa nada y pasa
 * por no tener trabajo. Este garantiza que, cuando los haya, la revisión sirva:
 * si alguien rompe `problemasDeCifrado`, esto se pone en rojo hoy.
 */
it('la guardia detecta un esquema mal armado', function (): void {
    Schema::create('modelos_mal_cifrados', function (Blueprint $tabla): void {
        $tabla->id();
        $tabla->string('nombre')->unique();   // cifrada, con UNIQUE encima y como varchar
        // falta nombre_hash, que el modelo declara como índice ciego
    });

    $problemas = problemasDeCifrado(new ModeloMalCifrado);

    expect($problemas)->toHaveCount(4)
        ->and(implode(' ', $problemas))
        ->toContain('hay un índice')
        ->toContain('es varchar')
        ->toContain('que no existe')
        ->toContain('no declara');
});
