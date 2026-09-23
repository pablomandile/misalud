<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\TamanioTexto;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve el tamaño de letra antes de renderizar, para que el atributo ya
 * viaje en el HTML de arranque.
 *
 * A diferencia del modo oscuro, acá **no hace falta un script inline**: no hay
 * opción "según el sistema", así que el servidor sabe la respuesta y la escribe
 * directo en `<html>`. Cero parpadeo y cero JavaScript.
 */
class HandleTamanioTexto
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        View::share('tamanioTexto', $this->resolver($request));

        return $next($request);
    }

    /**
     * **Manda la cuenta, después la cookie.**
     *
     * Es la cuenta la que viaja entre dispositivos, y ese es justamente el caso
     * que motiva la columna: alguien mayor estrenando un teléfono no debería
     * tener que volver a agrandar la letra. La cookie cubre a quien todavía no
     * inició sesión, y evita una consulta a la base por cada request de un
     * invitado.
     */
    private function resolver(Request $request): TamanioTexto
    {
        $usuario = $request->user();

        if ($usuario !== null) {
            return $usuario->tamanio_texto;
        }

        // Una cookie puede llegar como array si alguien la manda con corchetes.
        $cookie = $request->cookie('tamanio_texto');

        return TamanioTexto::desde(is_string($cookie) ? $cookie : null);
    }
}
