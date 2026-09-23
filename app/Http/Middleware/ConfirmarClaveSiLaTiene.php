<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confirmación de contraseña, salteada para quien no tiene ninguna.
 *
 * La pantalla de seguridad pide reconfirmar la contraseña antes de mostrarse.
 * Con el ingreso por Google hay cuentas que nunca eligieron una, y para esas el
 * `RequirePassword` de Laravel es una puerta sin llave posible: la confirmación
 * no puede pasar nunca, así que quedarían afuera del 2FA, de las llaves de
 * acceso y hasta de la posibilidad de definirse una contraseña -justo lo que
 * hay que hacer para salir de esa situación-.
 *
 * No baja la seguridad de nadie más: para quien tiene contraseña, se le sigue
 * pidiendo igual.
 */
class ConfirmarClaveSiLaTiene extends RequirePassword
{
    public function handle(
        $request,
        Closure $next,
        $redirectToRoute = null,
        $passwordTimeoutSeconds = null,
    ): Response {
        if ($this->sinContrasena($request)) {
            return $next($request);
        }

        return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
    }

    private function sinContrasena(Request $request): bool
    {
        $usuario = $request->user();

        return $usuario !== null && blank($usuario->getAuthPassword());
    }
}
