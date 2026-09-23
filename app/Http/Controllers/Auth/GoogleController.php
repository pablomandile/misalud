<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\IngresoConGoogleService;
use App\Support\CuentaDeGoogle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as RespuestaDeSocialite;
use Throwable;

/**
 * Ingreso con Google.
 *
 * Las dos rutas devuelven 404 si no hay credenciales configuradas, en vez de
 * explotar con un error de Socialite: así el código puede estar desplegado
 * antes de que exista el proyecto en Google Cloud, y la app se comporta como si
 * la opción no existiera.
 */
class GoogleController extends Controller
{
    public function __construct(private readonly IngresoConGoogleService $ingreso) {}

    /**
     * Manda a la pantalla de Google.
     */
    public function redirigir(): RespuestaDeSocialite|RedirectResponse
    {
        abort_unless(IngresoConGoogleService::configurado(), 404);

        return Socialite::driver('google')->redirect();
    }

    /**
     * Vuelta de Google: resuelve la cuenta e inicia la sesión.
     */
    public function volver(Request $request): RedirectResponse
    {
        abort_unless(IngresoConGoogleService::configurado(), 404);

        /*
         * El usuario tocó "Cancelar" en la pantalla de Google. No es un error:
         * vuelve al login sin ningún cartel rojo. Tratarlo como falla le diría
         * "algo salió mal" a alguien que hizo exactamente lo que quería.
         */
        if ($request->has('error')) {
            return redirect()->route('login');
        }

        try {
            $usuario = $this->ingreso->resolver(
                CuentaDeGoogle::desdeSocialite(Socialite::driver('google')->user()),
            );
        } catch (Throwable $e) {
            /*
             * El detalle va al log y no a la pantalla: los mensajes de Socialite
             * traen partes de la respuesta de Google, que no le dicen nada al
             * usuario y pueden incluir datos de la cuenta.
             */
            Log::warning('Falló el ingreso con Google.', ['excepcion' => $e->getMessage()]);

            return redirect()->route('login')->with(
                'error',
                'No pudimos entrar con Google. Probá de nuevo, o entrá con tu email y contraseña.',
            );
        }

        Auth::login($usuario, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
