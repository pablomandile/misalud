<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CoberturaGuardarRequest;
use App\Models\Cobertura;
use App\Models\Paciente;
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

    public function destroy(Cobertura $cobertura): RedirectResponse
    {
        Gate::authorize('delete', $cobertura);

        $cobertura->delete();

        return back()->with('exito', 'Se eliminó la cobertura.');
    }
}
