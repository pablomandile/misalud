<?php

namespace App\Http\Middleware;

use App\Enums\TamanioTexto;
use App\Services\IngresoConGoogleService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Inertia\Middleware;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Una misma URL de Inertia contesta dos cuerpos distintos según lleve o no
     * el header X-Inertia: el HTML de arranque, o el JSON de la página.
     *
     * Lo único que separa esas dos respuestas para una caché HTTP es
     * `Vary: X-Inertia`. En Hostinger el CDN lo BORRA al comprimir con brotli
     * -que es lo que pide cualquier navegador real-, y el `Cache-Control:
     * no-cache` que Symfony pone por defecto permite guardar (solo obliga a
     * revalidar). Cuando Chrome descarta una pestaña inactiva y la restaura,
     * esa navegación es de historial y reusa lo guardado SIN revalidar: el
     * navegador abre el JSON con su propio visor y la app nunca arranca.
     *
     * `no-store` en vez de `no-cache` es lo que corta esto de raíz: prohíbe
     * guardar, así que no hay nada que una navegación de historial pueda
     * reusar sin red.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = parent::handle($request, $next);

        $response->headers->set('Vary', Header::INERTIA.', Accept-Encoding');

        // Solo la respuesta XHR: `no-store` en el documento HTML desactivaría
        // el back/forward cache de Chrome y cada "atrás" sería una ida
        // completa a la red, sin ningún síntoma que lo delate.
        if ($request->header(Header::INERTIA)) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            /*
             * Sin credenciales en el .env el botón de Google no se dibuja.
             * Es el mismo chequeo que hacen las rutas, que dan 404: mostrarlo
             * igual sería una puerta pintada en la pared.
             */
            'googleHabilitado' => IngresoConGoogleService::configurado(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',

            /*
             * Lista liviana para el selector de paciente activo. `nombre` está
             * cifrado, así que NO hay orderBy en SQL: se trae todo (son
             * decenas de filas por usuario, como mucho) y se ordena acá.
             */
            'pacientes' => fn () => $request->user()
                ? $request->user()->pacientes()
                    ->get()
                    ->sortBy(fn ($paciente) => $paciente->nombre)
                    ->values()
                    ->map(fn ($paciente) => [
                        'id' => $paciente->id,
                        'nombre' => $paciente->nombre,
                    ])
                    ->all()
                : [],
            'pacienteActivoId' => fn () => $request->user()
                ? session('paciente_activo_id')
                : null,

            /*
             * El valor vigente y las opciones, para la pantalla de Configuración.
             * Lo resuelve HandleTamanioTexto, que corre antes que este middleware.
             */
            'tamanioTexto' => fn () => View::shared('tamanioTexto', TamanioTexto::porDefecto())->value,
            'tamaniosTexto' => fn () => TamanioTexto::opciones(),

            // Los avisos van en toast, disparados desde un solo lugar del layout.
            'flash' => function () use ($request): ?array {
                $exito = $request->session()->get('exito');
                $error = $request->session()->get('error');

                if ($exito === null && $error === null) {
                    return null;
                }

                return [
                    'exito' => $exito,
                    'error' => $error,
                    /*
                     * El `watch` del layout necesita ver un cambio para disparar.
                     * Sin un identificador nuevo en cada mensaje, dos éxitos
                     * seguidos con el mismo texto muestran un solo toast, y el
                     * síntoma se lee como "a veces no avisa".
                     *
                     * Se genera acá y no lo pone cada controlador: uno que se
                     * olvidara dejaría un aviso mudo sin que nada lo delate.
                     */
                    'id' => Str::uuid()->toString(),
                ];
            },
        ];
    }
}
