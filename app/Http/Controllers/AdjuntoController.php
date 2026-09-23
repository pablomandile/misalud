<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
     * La autorización es sobre el PACIENTE y no sobre el adjunto: el adjunto
     * todavía no existe, y lo que se está pidiendo es permiso para escribir
     * dentro de esa ficha.
     */
    public function store(AdjuntoStoreRequest $peticion, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('crearEn', [Adjunto::class, $paciente]);

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
     * La autorización se resuelve sobre el PACIENTE dueño de la cobertura y
     * no sobre la cobertura misma, por el mismo motivo que en `store()`: el
     * adjunto todavía no existe.
     */
    public function storeParaCobertura(AdjuntoStoreRequest $peticion, Cobertura $cobertura): RedirectResponse
    {
        Gate::authorize('crearEn', [Adjunto::class, $cobertura->paciente]);

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
             * - `no-store`: es contenido clínico. No queda en la caché del
             *   navegador de un locutorio ni en la de un CDN.
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
