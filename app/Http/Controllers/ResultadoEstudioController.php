<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ResultadoEstudioGuardarRequest;
use App\Models\Estudio;
use App\Models\ResultadoEstudio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Los parámetros de un estudio: "Glucemia: 90 mg/dl".
 *
 * Sin `index` propio -viajan como prop de `estudios/Index.vue`-, mismo
 * patrón que la bitácora de una enfermedad.
 */
class ResultadoEstudioController extends Controller
{
    public function store(ResultadoEstudioGuardarRequest $peticion, Estudio $estudio): RedirectResponse
    {
        // Cargar un parámetro es editar el estudio, no crear algo propio.
        Gate::authorize('update', $estudio);

        $estudio->resultados()->create($peticion->validated());

        return back()->with('exito', 'Se guardó el resultado.');
    }

    public function update(ResultadoEstudioGuardarRequest $peticion, ResultadoEstudio $resultado): RedirectResponse
    {
        Gate::authorize('update', $resultado);

        $resultado->update($peticion->validated());

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(ResultadoEstudio $resultado): RedirectResponse
    {
        Gate::authorize('delete', $resultado);

        $resultado->delete();

        return back()->with('exito', 'Se eliminó el resultado.');
    }
}
