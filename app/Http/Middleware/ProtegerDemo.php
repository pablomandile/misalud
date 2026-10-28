<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En la demo (APP_DEMO=true) la cuenta es una sola y la comparten todos los
 * visitantes: nadie se registra, entra con Google ni recupera la clave, y la
 * cuenta no se puede cambiar ni borrar. La historia clínica sí: es lo que se
 * quiere mostrar, y se resiembra de noche.
 *
 * ⚠️ Lo que más importa son 2FA y passkeys: un visitante que los activa deja
 * afuera a todos los demás hasta el reset.
 *
 * Tampoco sale ningún mail (decisión del usuario): invitar, mandar documentos y
 * pedir medicamentos vuelven con un aviso y no registran nada.
 */
class ProtegerDemo
{
    // Pantallas que no existen en la demo: van al login.
    private const PANTALLAS = [
        'register', 'register.store', 'google.redirect', 'google.callback',
        'password.request', 'password.reset',
    ];

    // Cambios de la cuenta: vuelven atrás con un aviso.
    private const CUENTA = [
        'password.email', 'password.update', 'user-password.update',
        'profile.update', 'profile.destroy', 'verification.send',
        'two-factor.enable', 'two-factor.disable', 'two-factor.confirm',
        'two-factor.regenerate-recovery-codes',
        'passkey.registration-options', 'passkey.store', 'passkey.destroy',
    ];

    // Lo que manda un mail: vuelve atrás sin mandar ni registrar nada.
    private const MAILS = ['envios.store', 'pedidos.store', 'pacientes.invitaciones.store'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('demo.enabled')) {
            return $next($request);
        }

        $ruta = $request->route()?->getName();

        if (in_array($ruta, self::PANTALLAS, true)) {
            return redirect()->route('login');
        }

        if (in_array($ruta, self::CUENTA, true)) {
            return $this->rechazar($request, 'En la demo no se puede cambiar la cuenta.');
        }

        if (in_array($ruta, self::MAILS, true)) {
            return $this->rechazar($request, 'En la demo no se mandan mails.');
        }

        return $next($request);
    }

    private function rechazar(Request $request, string $mensaje): Response
    {
        // Las passkeys y el 2FA se piden por fetch: un redirect no le sirve a nadie.
        if ($request->expectsJson()) {
            return response()->json(['message' => $mensaje], 403);
        }

        return back()->with('error', $mensaje);
    }
}
