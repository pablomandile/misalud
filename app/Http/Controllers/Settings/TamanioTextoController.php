<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TamanioTextoUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

class TamanioTextoController extends Controller
{
    /**
     * Guarda el tamaño de letra elegido.
     *
     * Se guarda en **los dos lados**, y cada uno cumple una función distinta:
     * la cookie es la que evita el salto de tamaño en la próxima carga —el
     * servidor la lee antes de renderizar—, y la columna del usuario es la que
     * hace que la preferencia lo siga a otro dispositivo.
     *
     * **No pide sesión.** Alguien que no llega a leer la pantalla de ingreso es
     * exactamente quien más necesita agrandar la letra, y ahí todavía no hay
     * cuenta donde guardarla. Sin sesión queda solo la cookie.
     */
    public function update(TamanioTextoUpdateRequest $request): RedirectResponse
    {
        $tamanio = $request->tamanio();

        $request->user()?->forceFill(['tamanio_texto' => $tamanio])->save();

        return back()->with('exito', 'Listo, cambiamos el tamaño de la letra.')
            ->withCookie(Cookie::forever('tamanio_texto', $tamanio->value));
    }
}
