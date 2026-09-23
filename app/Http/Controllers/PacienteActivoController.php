<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Paciente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class PacienteActivoController extends Controller
{
    /**
     * Cambia el paciente activo de la sesión: define de quién habla el
     * dashboard y las cargas rápidas cuando hay varios (uno mismo y su
     * familia, por ejemplo).
     */
    public function update(Paciente $paciente): RedirectResponse
    {
        Gate::authorize('view', $paciente);

        session(['paciente_activo_id' => $paciente->id]);

        return back();
    }
}
