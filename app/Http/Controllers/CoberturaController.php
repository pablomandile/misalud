<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoAdjunto;
use App\Http\Requests\CoberturaGuardarRequest;
use App\Models\Cobertura;
use App\Models\Paciente;
use App\Services\AdjuntadorDeArchivos;
use App\Services\ArchivoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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
    public function __construct(private readonly AdjuntadorDeArchivos $adjuntador) {}

    /**
     * La cobertura y, si vino, la foto de la credencial (frente y dorso) en el
     * mismo paso: es lo primero que se tiene en la mano al cargarla. Juntas o
     * nada, como el alta de una orden con su papel.
     */
    public function store(CoberturaGuardarRequest $peticion, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('crearEn', [Cobertura::class, $paciente]);

        DB::transaction(function () use ($peticion, $paciente): void {
            $cobertura = $paciente->coberturas()->create([
                ...$peticion->safe()->except('archivos'),
                // Ausente cuando el checkbox llega destildado: un checkbox HTML
                // sin marcar no manda el campo, no manda "false".
                'activa' => $peticion->boolean('activa', true),
            ]);

            $this->adjuntador->adjuntar($cobertura, $peticion->file('archivos', []), TipoAdjunto::Credencial);
        });

        return back()->with('exito', 'Se agregó la cobertura.');
    }

    public function update(CoberturaGuardarRequest $peticion, Cobertura $cobertura): RedirectResponse
    {
        Gate::authorize('update', $cobertura);

        $cobertura->update([
            ...$peticion->safe()->except('archivos'),
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
