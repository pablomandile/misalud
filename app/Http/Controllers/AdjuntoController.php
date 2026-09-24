<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\TieneArchivos;
use App\Enums\TipoAdjunto;
use App\Http\Requests\AdjuntoStoreRequest;
use App\Models\Adjunto;
use App\Models\Cobertura;
use App\Models\Estudio;
use App\Models\Medicamento;
use App\Models\OrdenEstudio;
use App\Models\Paciente;
use App\Services\ArchivoService;
use Illuminate\Database\Eloquent\Model;
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
        return $this->guardarEn($peticion, $paciente);
    }

    /**
     * La credencial de una cobertura, frente y dorso, como dos adjuntos
     * tipo `credencial`.
     *
     * Se pide `update` sobre la COBERTURA, que a su vez lo resuelve por el
     * rol en el paciente. Preguntarle directo al dueño -y no salteárselo
     * para ir al paciente- es lo que hace que la regla sea una sola para
     * todos los dueños posibles, incluidos los que no tienen paciente.
     */
    public function storeParaCobertura(AdjuntoStoreRequest $peticion, Cobertura $cobertura): RedirectResponse
    {
        return $this->guardarEn($peticion, $cobertura);
    }

    /**
     * El prospecto de un medicamento del catálogo.
     *
     * Sale gratis una regla correcta: una semilla compartida no admite
     * prospecto propio, porque la niega `CatalogoPolicy::update()` —la misma
     * regla que ya impide editarla— (ver `AdjuntoPolicy`).
     */
    public function storeParaMedicamento(AdjuntoStoreRequest $peticion, Medicamento $medicamento): RedirectResponse
    {
        return $this->guardarEn($peticion, $medicamento);
    }

    /**
     * El papel que dio el médico: la orden de estudio, en PDF o foto.
     */
    public function storeParaOrden(AdjuntoStoreRequest $peticion, OrdenEstudio $orden): RedirectResponse
    {
        return $this->guardarEn($peticion, $orden);
    }

    /**
     * El informe de un estudio ya hecho, o la imagen cruda (una
     * radiografía, por ejemplo).
     */
    public function storeParaEstudio(AdjuntoStoreRequest $peticion, Estudio $estudio): RedirectResponse
    {
        return $this->guardarEn($peticion, $estudio);
    }

    /**
     * El cuerpo que comparten los cinco.
     *
     * Cada dueño tiene su método con su type-hint concreto -hace falta para
     * el route-model binding, igual que en los catálogos-, pero el cuerpo
     * vive una sola vez: con cinco copias, cada una era un lugar donde
     * olvidarse el `Gate::authorize` o escribir mal el prefijo del disco.
     *
     * El prefijo lo declara el modelo (`carpetaDeArchivos()`) y no se arma
     * acá, para que dos dueños distintos no puedan terminar escribiendo en
     * la misma carpeta porque alguien copió una línea sin cambiar el string.
     */
    private function guardarEn(
        AdjuntoStoreRequest $peticion,
        Model&TieneArchivos $duenio,
    ): RedirectResponse {
        Gate::authorize('update', $duenio);

        $tipo = $peticion->tipo();
        $descripcion = $peticion->input('descripcion');
        $subidos = 0;

        foreach ($peticion->file('archivos', []) as $archivo) {
            $datos = $this->archivos->guardar($archivo, $duenio->carpetaDeArchivos());

            /*
             * Por la relación y no armando `adjuntable_*` a mano: de esa
             * referencia cuelga toda la autorización, y por eso no es fillable.
             */
            $duenio->adjuntos()->create($datos + [
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
