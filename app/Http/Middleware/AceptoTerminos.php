<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Frena a quien no aceptó la versión vigente de los términos y la privacidad.
 *
 * Cubre los tres casos que no pasan por la casilla del registro: las cuentas de
 * antes de que existieran los textos, el alta con Google (que crea la cuenta sin
 * casilla) y cualquier cambio futuro de versión (`config('legales.version')`).
 *
 * Va en todo el grupo `web` y no ruta por ruta: así una ruta nueva nace
 * cubierta, y lo que se exceptúa está a la vista en una sola lista, que
 * `TerminosYPrivacidadTest` vigila para que no crezca sin querer.
 */
class AceptoTerminos
{
    /**
     * Lo que se puede hacer sin aceptar, y por qué:
     *
     * - leer los textos, las cookies, la portada y la pantalla sin conexión;
     * - salir;
     * - los dos derechos que no pueden depender de aceptar nada: **llevarse los
     *   datos** (`exportacion.datos`) y **eliminar la cuenta** (el perfil, donde
     *   está el botón, y la acción misma);
     * - verificar el mail, que es anterior a todo lo demás;
     * - **agrandar la letra**: quien no llega a leer la pantalla de aceptación
     *   es justo quien más lo necesita.
     *
     * @var list<string>
     */
    public const EXENTAS = [
        'terminos',
        'terminos.*',
        'privacidad',
        'cookies',
        'home',
        'offline',
        'logout',
        'profile.edit',
        'profile.destroy',
        'exportacion.datos',
        'verification.*',
        'tamanio-texto.update',
        'storage.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario instanceof User || $usuario->aceptoTerminosVigentes() || $this->exenta($request)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => 'Antes de seguir tenés que aceptar los términos y la política de privacidad.',
            ], 409);
        }

        // `guest()` guarda la URL pedida, si era un GET, para volver ahí al aceptar.
        return redirect()->guest(route('terminos.aceptar'));
    }

    private function exenta(Request $request): bool
    {
        $nombre = $request->route()?->getName();

        // Sin nombre: rutas de paquetes (.well-known, passkeys), no pantallas.
        return $nombre === null || Str::is(self::EXENTAS, $nombre);
    }
}
