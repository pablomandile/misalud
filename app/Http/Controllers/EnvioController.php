<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoEnvio;
use App\Enums\TipoContacto;
use App\Http\Requests\EnvioGuardarRequest;
use App\Models\Adjunto;
use App\Models\Contacto;
use App\Models\Envio;
use App\Models\Receta;
use App\Models\User;
use App\Services\DocumentosEnviables;
use App\Services\EnviadorDeDocumentos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Armar y mandar un envío de documentación.
 *
 * El envío **arranca desde un documento** —el botón "Enviar" al lado de una
 * receta, una orden, un estudio o una receta de anteojos— y no desde una pantalla
 * en blanco: así ya se sabe de qué paciente se trata, y lo que se ofrece para sumar
 * es de esa misma ficha (ver `DocumentosEnviables`).
 */
class EnvioController extends Controller
{
    public function create(Request $peticion, DocumentosEnviables $documentos): Response|RedirectResponse
    {
        Gate::authorize('create', Envio::class);

        /** @var User $usuario */
        $usuario = $peticion->user();

        $ids = collect((array) $peticion->query('adjuntos', []))
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return redirect()->route('contactos.index')->with(
                'error',
                'Para mandar algo, abrí el documento y tocá "Enviar".',
            );
        }

        $elegidos = Adjunto::query()->with('adjuntable')->whereKey($ids->all())->get();

        abort_if($elegidos->isEmpty(), 404);

        // Cada uno, igual que si se lo intentara abrir.
        foreach ($elegidos as $adjunto) {
            Gate::authorize('view', $adjunto);
        }

        /** @var Adjunto $inicial */
        $inicial = $elegidos->sortBy(fn (Adjunto $a): int|false => $ids->search($a->id))->first();
        $paciente = $documentos->pacienteDe($inicial);

        // Lo del mismo ámbito, más lo elegido aunque venga de otro (ya autorizado).
        $ofrecidos = $documentos->junto($usuario, $inicial)
            ->concat($elegidos)
            ->unique('id')
            ->values();

        return Inertia::render('envios/Nuevo', [
            'documentos' => $ofrecidos
                ->map(fn (Adjunto $adjunto): array => [
                    'id' => $adjunto->id,
                    'descripcion' => $documentos->describir($adjunto),
                    'nombre' => $adjunto->nombre_original,
                    'mime' => $adjunto->mime,
                    'tamanio' => $adjunto->tamanio_bytes,
                    'url' => route('adjuntos.show', $adjunto),
                    'elegido' => $ids->contains($adjunto->id),
                ])
                ->all(),

            'contactos' => $usuario->contactos()
                ->get()
                ->sortBy(fn (Contacto $c): string => mb_strtolower($c->nombre))
                ->values()
                ->map(fn (Contacto $c): array => [
                    'id' => $c->id,
                    'nombre' => $c->nombre,
                    'email' => $c->email,
                    'tipoEtiqueta' => $c->tipo->etiqueta(),
                ])
                ->all(),

            'pacienteNombre' => $paciente?->nombre,
            'asuntoSugerido' => $paciente !== null
                ? "Documentación de {$paciente->nombre}"
                : ($inicial->adjuntable instanceof Receta ? 'Receta' : 'Documentación'),
            'maximoBytes' => EnviadorDeDocumentos::MAXIMO_BYTES,
            // Para cargar un contacto sin salir del armado del envío.
            'tipos' => array_map(
                fn (TipoContacto $tipo): array => ['valor' => $tipo->value, 'etiqueta' => $tipo->etiqueta()],
                TipoContacto::cases(),
            ),
        ]);
    }

    /**
     * Manda, **síncrono**: la persona está mirando la pantalla, y lo que necesita
     * saber es si salió o no, ahora. Ver `EnvioDeDocumentos` para por qué además
     * no se puede encolar.
     */
    public function store(EnvioGuardarRequest $peticion, EnviadorDeDocumentos $enviador): RedirectResponse
    {
        Gate::authorize('create', Envio::class);

        /** @var User $usuario */
        $usuario = $peticion->user();

        $contacto = $usuario->contactos()->findOrFail((int) $peticion->validated('contacto_id'));

        $envio = $enviador->enviar(
            $usuario,
            $contacto,
            $peticion->adjuntos(),
            (string) $peticion->validated('asunto'),
            $peticion->validated('mensaje'),
        );

        if ($envio->estado === EstadoEnvio::Fallido) {
            /*
             * De vuelta al armado, con lo elegido intacto: el error casi siempre
             * es del servidor de correo, y la salida es reintentar en un rato.
             */
            return back()->with(
                'error',
                'No se pudo mandar. Quedó anotado como no enviado; probá de nuevo en un rato.',
            );
        }

        // A la libreta, donde el envío nuevo aparece arriba del historial.
        return redirect()
            ->route('contactos.index')
            ->with('exito', "Se mandó a {$contacto->nombre}.");
    }
}
