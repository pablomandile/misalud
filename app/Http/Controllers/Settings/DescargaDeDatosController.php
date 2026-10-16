<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ExportadorDeDatos;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * "Descargar mis datos": el derecho de acceso, ejercido sin pedírselo a nadie.
 * Lo promete la política de privacidad, y por eso está exento del middleware
 * de los términos —no puede depender de aceptar nada—.
 */
class DescargaDeDatosController extends Controller
{
    public function __invoke(Request $request, ExportadorDeDatos $exportador): BinaryFileResponse
    {
        $zip = $exportador->armar($request->user());

        $respuesta = response()
            ->download($zip, 'mis-datos-misalud-'.now()->format('Y-m-d').'.zip')
            ->deleteFileAfterSend();

        /*
         * Tiene la historia clínica descifrada: que ninguna caché la guarde, ni
         * la del navegador ni una intermedia. Symfony marca `public` las
         * descargas de archivos por defecto, así que se pisa explícito.
         */
        $respuesta->setPrivate();
        $respuesta->headers->addCacheControlDirective('no-store');

        return $respuesta;
    }
}
