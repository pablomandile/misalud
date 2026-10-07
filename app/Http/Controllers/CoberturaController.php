<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CoberturaGuardarRequest;
use App\Models\Cobertura;
use App\Models\Paciente;
use App\Services\ArchivoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * CRUD de coberturas médicas.
 *
 * No tiene `index` propio: viaja como prop de la ficha del paciente (ver
 * `PacienteController::serializar`), el mismo patrón que ya usan los
 * adjuntos. Una cobertura no tiene sentido fuera de esa pantalla.
 */
class CoberturaController extends Controller
{
    public function store(CoberturaGuardarRequest $peticion, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('crearEn', [Cobertura::class, $paciente]);

        $paciente->coberturas()->create([
            ...$peticion->validated(),
            // Ausente cuando el checkbox llega destildado: un checkbox HTML
            // sin marcar no manda el campo, no manda "false".
            'activa' => $peticion->boolean('activa', true),
        ]);

        return back()->with('exito', 'Se agregó la cobertura.');
    }

    public function update(CoberturaGuardarRequest $peticion, Cobertura $cobertura): RedirectResponse
    {
        Gate::authorize('update', $cobertura);

        $cobertura->update([
            ...$peticion->validated(),
            'activa' => $peticion->boolean('activa'),
        ]);

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    /**
     * Borra DE VERDAD, con la credencial: no hay papelera (decisión del
     * usuario). Con papelera, la fila borrada seguía ocupando su `entidad_hash`
     * en el UNIQUE, la validación no la veía, y volver a cargar "OSDE" daba un
     * 500 —sin que existiera ninguna forma de restaurarla—. Para "ya no la uso"
     * está destildar `activa`, que la deja en el historial; borrar es para lo que
     * se cargó mal.
     */
    public function destroy(Cobertura $cobertura, ArchivoService $archivos): RedirectResponse
    {
        Gate::authorize('delete', $cobertura);

        // Primero el disco, después las filas: la regla de los adjuntos.
        $archivos->borrarTodosDe($cobertura);
        $cobertura->delete();

        return back()->with('exito', 'Se eliminó la cobertura.');
    }
}
