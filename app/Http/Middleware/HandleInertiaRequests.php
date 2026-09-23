<?php

namespace App\Http\Middleware;

use App\Enums\TamanioTexto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Inertia\Middleware;

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
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',

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
