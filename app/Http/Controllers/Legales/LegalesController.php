<?php

namespace App\Http\Controllers\Legales;

use App\Http\Controllers\Controller;
use App\Http\Requests\AceptarTerminosRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los términos, la política de privacidad y su aceptación.
 *
 * Los textos viven en `resources/legales/*.md` y se convierten acá: una sola
 * fuente, que se edita como texto y no como plantilla de Vue.
 */
class LegalesController extends Controller
{
    private const TEXTOS = [
        'terminos' => 'Términos y condiciones',
        'privacidad' => 'Política de privacidad',
    ];

    public function terminos(): Response
    {
        return $this->texto('terminos');
    }

    public function privacidad(): Response
    {
        return $this->texto('privacidad');
    }

    public function aceptar(Request $request): Response
    {
        return Inertia::render('terminos/Aceptar', [
            'primeraVez' => $request->user()?->terminos_version === null,
            'version' => $this->versionLegible(),
        ]);
    }

    public function guardarAceptacion(AceptarTerminosRequest $request): RedirectResponse
    {
        $request->user()->aceptarTerminos();

        return redirect()->intended(route('dashboard'));
    }

    private function texto(string $cual): Response
    {
        $markdown = (string) file_get_contents(resource_path("legales/{$cual}.md"));

        return Inertia::render('Legal', [
            'titulo' => self::TEXTOS[$cual],
            'version' => $this->versionLegible(),
            // Texto propio, del repo: no hay entrada de usuario que escapar.
            'html' => Str::markdown($markdown, ['html_input' => 'escape']),
        ]);
    }

    private function versionLegible(): string
    {
        $version = config('legales.version');

        // El locale de la app ya es `es`: «15 de octubre de 2026».
        return (new Carbon(is_string($version) ? $version : 'now'))
            ->translatedFormat('j \d\e F \d\e Y');
    }
}
