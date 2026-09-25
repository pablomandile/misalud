<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RecordatorioGuardarRequest;
use App\Models\Recordatorio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * **Un solo método, y eso es el diseño.**
 *
 * Un recordatorio no se crea ni se borra desde ninguna pantalla: nace porque
 * existe su origen y muere con él, y de eso se encargan los observers. Lo
 * único que decide una persona es si ya lo resolvió.
 *
 * Que no haya `store` ni `destroy` no es una función que falte: es lo que
 * garantiza que la tabla no pueda entrar en un estado que ningún origen
 * justifique.
 */
class RecordatorioController extends Controller
{
    public function update(
        RecordatorioGuardarRequest $peticion,
        Recordatorio $recordatorio,
    ): RedirectResponse {
        Gate::authorize('update', $recordatorio);

        if ($peticion->boolean('completado')) {
            $recordatorio->completar();

            return back()->with('exito', 'Marcado como hecho.');
        }

        $recordatorio->reabrir();

        return back()->with('exito', 'Volvió a quedar pendiente.');
    }
}
