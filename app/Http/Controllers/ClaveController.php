<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ClaveGuardarRequest;
use App\Models\Clave;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las claves de los sitios de salud, agrupadas por ficha.
 *
 * ⚠️ **La clave no viaja nunca en los props de Inertia**, ni en el listado ni
 * en el formulario de edición: sale únicamente por `revelar()`, de a una, con
 * la contraseña de la cuenta reconfirmada hace menos de
 * `SEGUNDOS_DESBLOQUEO`. Un HTML de Inertia queda en el historial y en la
 * memoria del navegador; una respuesta JSON pedida a propósito, no.
 */
class ClaveController extends Controller
{
    /**
     * Cuánto dura el desbloqueo. Corto a propósito: en la pantalla de seguridad
     * la confirmación vale tres horas, pero acá lo que se abre son contraseñas,
     * y un teléfono olvidado en la sala de espera no tiene que mostrarlas.
     */
    public const SEGUNDOS_DESBLOQUEO = 300;

    public function index(): Response
    {
        $usuario = $this->usuario();

        $pacientes = $usuario->pacientes()
            ->with(['claves', 'cuidadores'])
            ->get()
            // Cifrados: el orden se resuelve acá, no en SQL.
            ->sortBy(fn (Paciente $paciente): string => mb_strtolower($paciente->nombre))
            ->values()
            ->map(function (Paciente $paciente) use ($usuario): array {
                $puedeEditar = $paciente->rolDe($usuario)?->puedeEditar() ?? false;

                return [
                    'id' => $paciente->id,
                    'nombre' => $paciente->nombre,
                    'puedeEditar' => $puedeEditar,
                    'claves' => $paciente->claves
                        ->sortBy(fn (Clave $clave): string => mb_strtolower($clave->sitio))
                        ->values()
                        ->map(fn (Clave $clave): array => [
                            'id' => $clave->id,
                            'sitio' => $clave->sitio,
                            'usuario' => $clave->usuario,
                            'notas' => $clave->notas,
                        ])
                        ->all(),
                ];
            })
            ->all();

        return Inertia::render('claves/Index', [
            'pacientes' => $pacientes,
            'desbloqueado' => $this->desbloqueado($usuario),
            'segundosDesbloqueo' => self::SEGUNDOS_DESBLOQUEO,
        ]);
    }

    public function store(ClaveGuardarRequest $peticion, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('crearEn', [Clave::class, $paciente]);

        $clave = $paciente->claves()->create($peticion->validated());

        return back()->with('exito', "Se guardó la clave de {$clave->sitio}.");
    }

    public function update(ClaveGuardarRequest $peticion, Clave $clave): RedirectResponse
    {
        Gate::authorize('update', $clave);

        $datos = $peticion->validated();

        // Vacía significa "dejá la que está": el formulario nunca la recibió.
        if (blank($datos['clave'] ?? null)) {
            unset($datos['clave']);
        }

        $clave->update($datos);

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    /**
     * Borra DE VERDAD, sin papelera: es una contraseña, y quien la borra espera
     * que deje de estar guardada.
     */
    public function destroy(Clave $clave): RedirectResponse
    {
        Gate::authorize('delete', $clave);

        $sitio = $clave->sitio;
        $clave->delete();

        return back()->with('exito', "Se borró la clave de {$sitio}.");
    }

    /**
     * Pasa por la confirmación de contraseña (lo hace el middleware de la
     * ruta) y vuelve al listado, ya desbloqueado.
     */
    public function desbloquear(): RedirectResponse
    {
        return to_route('claves.index');
    }

    /**
     * La única salida de una clave en claro. Pide `update` y no `view`: el
     * lector ve que la cuenta existe, pero no la contraseña.
     */
    public function revelar(Clave $clave): JsonResponse
    {
        Gate::authorize('update', $clave);

        return response()
            ->json(['clave' => $clave->clave])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Solo decide qué botón dibuja la pantalla. Lo que protege de verdad es el
     * middleware de `revelar`, que mira lo mismo.
     */
    private function desbloqueado(User $usuario): bool
    {
        if (blank($usuario->getAuthPassword())) {
            return true;
        }

        $confirmada = (int) session('auth.password_confirmed_at', 0);

        return Date::now()->unix() - $confirmada <= self::SEGUNDOS_DESBLOQUEO;
    }

    private function usuario(): User
    {
        $usuario = auth()->user();
        assert($usuario instanceof User);

        return $usuario;
    }
}
