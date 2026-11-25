<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ProcedimientoGuardarRequest;
use App\Models\Tratamiento;
use App\Services\SeguimientoDePedido;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Las etapas del procedimiento para pedir un medicamento de la ficha. Se
 * guarda la lista entera de una vez, en el orden en que llega.
 */
class ProcedimientoController extends Controller
{
    public function update(ProcedimientoGuardarRequest $peticion, Tratamiento $tratamiento, SeguimientoDePedido $seguimiento): RedirectResponse
    {
        Gate::authorize('update', $tratamiento);

        /** @var list<array{id?: int|string|null, nombre: string}> $etapas */
        $etapas = $peticion->validated('etapas', []);

        $seguimiento->definirEtapas($tratamiento, $etapas);

        return back()->with('exito', 'Se guardaron las etapas.');
    }
}
