<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RegistroEnfermedadGuardarRequest;
use App\Models\Enfermedad;
use App\Models\RegistroEnfermedad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * La bitácora de una enfermedad: qué fue pasando, en texto.
 *
 * Sin `index` propio —viaja como prop de la pantalla de enfermedades— y sin
 * edición: una anotación es lo que se anotó ese día. Si está mal, se borra
 * y se escribe de nuevo; corregir el pasado en silencio es justo lo que no
 * quiere una historia clínica.
 */
class RegistroEnfermedadController extends Controller
{
    public function store(RegistroEnfermedadGuardarRequest $peticion, Enfermedad $enfermedad): RedirectResponse
    {
        // Anotar en la bitácora es editar la enfermedad, no crear algo
        // propio: es la misma regla que usan los adjuntos con su dueño.
        Gate::authorize('update', $enfermedad);

        $enfermedad->registros()->create($peticion->validated());

        return back()->with('exito', 'Se guardó la anotación.');
    }

    public function destroy(RegistroEnfermedad $registro): RedirectResponse
    {
        Gate::authorize('delete', $registro);

        $registro->delete();

        return back()->with('exito', 'Se eliminó la anotación.');
    }
}
