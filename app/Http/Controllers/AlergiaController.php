<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AlergiaGuardarRequest;
use App\Models\Alergia;
use App\Models\Paciente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Las alergias de un paciente.
 *
 * Sin `index` propio: viajan como prop de la pantalla de enfermedades, el
 * mismo patrón que las coberturas en la ficha del paciente. Son pocas filas
 * y se consultan junto con el resto de las condiciones de salud.
 */
class AlergiaController extends Controller
{
    public function store(AlergiaGuardarRequest $peticion, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('crearEn', [Alergia::class, $paciente]);

        $alergia = $paciente->alergias()->create($peticion->validated());

        return back()->with('exito', "Se agregó la alergia a {$alergia->sustancia}.");
    }

    public function update(AlergiaGuardarRequest $peticion, Alergia $alergia): RedirectResponse
    {
        Gate::authorize('update', $alergia);

        $alergia->update($peticion->validated());

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(Alergia $alergia): RedirectResponse
    {
        Gate::authorize('delete', $alergia);

        $sustancia = $alergia->sustancia;
        $alergia->delete();

        return back()->with('exito', "Se eliminó la alergia a {$sustancia}.");
    }
}
