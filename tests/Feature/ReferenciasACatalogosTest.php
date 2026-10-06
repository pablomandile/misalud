<?php

declare(strict_types=1);

use App\Http\Controllers\CatalogoBaseController;
use App\Http\Controllers\CentroController;
use App\Http\Controllers\MedicamentoController;
use App\Http\Controllers\MedicoController;
use App\Http\Controllers\TipoMedicionController;
use App\Http\Controllers\VacunaController;
use App\Support\UsoDeCatalogo;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Toda FK que apunte a un catálogo tiene que estar declarada en sus `usos()`
|--------------------------------------------------------------------------
|
| `usos()` es lo único que frena el borrado de un catálogo. Una FK nueva que no
| figure ahí deja borrar un médico que alguien sigue usando -con la FK haciendo
| lo que diga su migración: dejar el registro sin médico, o un 500 si es
| `restrictOnDelete`-. Esto lee el esquema REAL, igual que `GuardiaDeCifradoTest`,
| para que no dependa de que alguien se acuerde. El caso anunciado es
| `aplicaciones_vacuna`, que va a apuntar a `vacunas`.
|
*/

/** @return array<string, class-string<CatalogoBaseController<*>>> */
function controladoresDeCatalogo(): array
{
    return [
        'medicos' => MedicoController::class,
        'centros' => CentroController::class,
        'medicamentos' => MedicamentoController::class,
        'vacunas' => VacunaController::class,
        'tipos_medicion' => TipoMedicionController::class,
    ];
}

/**
 * Las FK hacia un catálogo que el esquema tiene, como "tabla.columna" => catálogo.
 *
 * @return array<string, string>
 */
function referenciasACatalogos(): array
{
    /*
     * Las que NO frenan el borrado, a propósito: que un médico atienda en un
     * centro es configuración del catálogo, no un registro de la historia de
     * nadie. Su FK es `cascadeOnDelete`, y se van solas con el borrado.
     */
    $deliberadas = ['centro_medico.medico_id', 'centro_medico.centro_id'];

    $referencias = [];

    foreach (Schema::getTables() as $tabla) {
        foreach (Schema::getForeignKeys($tabla['name']) as $fk) {
            $destino = $fk['foreign_table'];

            if (! array_key_exists($destino, controladoresDeCatalogo())) {
                continue;
            }

            $clave = $tabla['name'].'.'.$fk['columns'][0];

            if (! in_array($clave, $deliberadas, true)) {
                $referencias[$clave] = $destino;
            }
        }
    }

    return $referencias;
}

it('toda FK hacia un catálogo está declarada en los usos de su controlador', function (): void {
    $faltan = [];

    foreach (referenciasACatalogos() as $clave => $catalogo) {
        $declarados = array_map(
            fn (UsoDeCatalogo $uso): string => $uso->tabla().'.'.$uso->columna,
            app(controladoresDeCatalogo()[$catalogo])->usos(),
        );

        if (! in_array($clave, $declarados, true)) {
            $faltan[] = "{$clave} apunta a [{$catalogo}] y no figura en "
                .class_basename(controladoresDeCatalogo()[$catalogo]).'::usos(): '
                .'se podría borrar un registro que todavía se usa.';
        }
    }

    expect($faltan)->toBe([], "\n - ".implode("\n - ", $faltan)."\n");
});

it('ningún uso declarado apunta a una FK que no existe', function (): void {
    // Al revés: un uso con una columna mal escrita contaría siempre cero, y el
    // freno no frenaría nunca -sin ningún error-.
    $existentes = array_keys(referenciasACatalogos());
    $sobran = [];

    foreach (controladoresDeCatalogo() as $catalogo => $controlador) {
        foreach (app($controlador)->usos() as $uso) {
            $clave = $uso->tabla().'.'.$uso->columna;

            if (! in_array($clave, $existentes, true)) {
                $sobran[] = "{$clave} (en ".class_basename($controlador).')';
            }
        }
    }

    expect($sobran)->toBe([]);
});

it('la guardia lee el esquema de verdad, no una lista vacía', function (): void {
    // Si `getForeignKeys` no devolviera nada, los dos tests de arriba pasarían
    // sin revisar nada. Estas tienen que estar.
    expect(referenciasACatalogos())->toHaveKeys([
        'estudios.medico_id',
        'turnos.centro_id',
        'tratamientos.medicamento_id',
        'mediciones.tipo_medicion_id',
    ]);
});
