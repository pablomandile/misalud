<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoAdjunto;
use App\Http\Requests\AdjuntoStoreRequest;
use App\Models\Adjunto;
use App\Models\Cobertura;
use App\Models\Paciente;
use App\Services\ArchivoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sirve y elimina archivos de la historia clínica.
 *
 * **Siempre por acá, nunca por URL pública.** Los archivos viven en el disco
 * privado y cifrados: no hay forma de linkearlos directamente, y es a propósito.
 */
class AdjuntoController extends Controller
{
    public function __construct(private readonly ArchivoService $archivos) {}

    /**
     * Sube uno o varios archivos a la ficha de un paciente.
     *
     * **Subir un archivo a algo es editar ese algo**: se pide `update` sobre
     * el paciente, no un permiso propio del adjunto -que todavía no existe-.
     * Es la misma regla que aplica `AdjuntoPolicy` para ver y para borrar, y
     * la que hace que esto funcione igual para una cobertura o un catálogo.
     */
    public function store(AdjuntoStoreRequest $peticion, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('update', $paciente);

        $tipo = $peticion->tipo();
        $descripcion = $peticion->input('descripcion');
        $subidos = 0;

        foreach ($peticion->file('archivos', []) as $archivo) {
            $datos = $this->archivos->guardar($archivo, 'pacientes/'.$paciente->id);

            /*
             * Por la relación y no armando `adjuntable_*` a mano: de esa
             * referencia cuelga toda la autorización, y por eso no es fillable.
             */
            $paciente->adjuntos()->create($datos + [
                'tipo' => $tipo,
                'descripcion' => $descripcion,
            ]);

            $subidos++;
        }

        return back()->with('exito', $subidos === 1
            ? 'Se guardó el documento.'
            : "Se guardaron {$subidos} documentos.");
    }

    /**
     * Sube uno o varios archivos a una cobertura: la credencial, frente y
     * dorso, como dos adjuntos tipo `credencial`.
     *
     * Se pide `update` sobre la COBERTURA, que a su vez lo resuelve por el
     * rol en el paciente. Preguntarle directo al dueño -y no salteárselo
     * para ir al paciente- es lo que hace que la regla sea una sola para
     * todos los dueños posibles, incluidos los que no tienen paciente.
     */
    public function storeParaCobertura(AdjuntoStoreRequest $peticion, Cobertura $cobertura): RedirectResponse
    {
        Gate::authorize('update', $cobertura);

        $tipo = $peticion->tipo();
        $descripcion = $peticion->input('descripcion');
        $subidos = 0;

        foreach ($peticion->file('archivos', []) as $archivo) {
            $datos = $this->archivos->guardar($archivo, 'coberturas/'.$cobertura->id);

            $cobertura->adjuntos()->create($datos + [
                'tipo' => $tipo,
                'descripcion' => $descripcion,
            ]);

            $subidos++;
        }

        return back()->with('exito', $subidos === 1
            ? 'Se guardó el documento.'
            : "Se guardaron {$subidos} documentos.");
    }

    /**
     * Devuelve el archivo descifrado, para mostrarlo dentro de la app.
     */
    public function show(Adjunto $adjunto): Response
    {
        Gate::authorize('view', $adjunto);

        return $this->respuestaDelArchivo($adjunto);
    }

    /**
     * La credencial, por una ruta APARTE de `show()`.
     *
     * Es la única excepción a "las respuestas clínicas no se cachean": el
     * service worker la guarda para poder mostrarla en un mostrador sin
     * señal, que es justo donde más hace falta. La excepción está acotada acá
     * y no en el cliente: `abort_unless` rechaza cualquier adjunto que no sea
     * `credencial`, así que ni un bug del frontend ni una URL armada a mano
     * pueden colar un documento clínico de verdad por el único camino que el
     * service worker trata como cacheable. `Cache-Control` sigue en
     * `no-store` igual -eso es la caché HTTP del navegador, compartida por
     * cualquier pestaña del mismo origen-; lo que cachea la credencial es el
     * service worker, por la Cache Storage API, que es un almacén aparte y
     * solo esta app lo controla.
     */
    public function showCredencial(Adjunto $adjunto): Response
    {
        Gate::authorize('view', $adjunto);

        abort_unless($adjunto->tipo === TipoAdjunto::Credencial, 404);

        return $this->respuestaDelArchivo($adjunto);
    }

    private function respuestaDelArchivo(Adjunto $adjunto): Response
    {
        $contenido = $this->archivos->contenido($adjunto);

        return response($contenido, 200, [
            'Content-Type' => $adjunto->mime,
            'Content-Length' => (string) strlen($contenido),

            /*
             * `inline` para que lo muestre el visor de la app en vez de
             * disparar una descarga. El nombre va entre comillas y ya viene
             * limpio de saltos de línea y comillas desde ArchivoService: un
             * nombre sin sanear en este header permite inyectar headers.
             */
            'Content-Disposition' => 'inline; filename="'.$adjunto->nombre_original.'"',

            /*
             * Tres cerrojos sobre contenido que subió un usuario y se sirve
             * desde nuestro propio dominio:
             *
             * - `nosniff`: sin esto el navegador puede decidir por su cuenta
             *   que un archivo es HTML aunque digamos que es un PDF, y
             *   ejecutarlo. La lista blanca de ArchivoService ya lo evita en la
             *   subida; esto cubre lo que haya entrado antes o por otro camino.
             * - `Content-Security-Policy: sandbox`: aunque algo llegara a
             *   interpretarse como documento, queda sin scripts y sin origen.
             * - `no-store`: es contenido clínico. No queda en la caché HTTP
             *   del navegador de un locutorio ni en la de un CDN. La única
             *   excepción es la credencial, y esa la guarda el SERVICE
             *   WORKER -Cache Storage, no la caché HTTP-, que es otro almacén.
             */
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self'; object-src 'none'",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Borra el archivo y su registro.
     */
    public function destroy(Adjunto $adjunto): RedirectResponse
    {
        Gate::authorize('delete', $adjunto);

        /*
         * Primero el disco y después la fila. Al revés, si el borrado de la
         * fila anda y el del archivo no, queda un archivo cifrado en el disco
         * sin nada que lo referencie: invisible y para siempre.
         */
        $this->archivos->borrar($adjunto);
        $adjunto->delete();

        return back()->with('exito', 'Se eliminó el documento.');
    }
}
