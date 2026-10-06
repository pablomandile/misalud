<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoContacto;
use App\Http\Requests\ContactoGuardarRequest;
use App\Models\ArchivoEnviado;
use App\Models\Cobertura;
use App\Models\Contacto;
use App\Models\Envio;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La libreta de contactos y, debajo, lo que se les mandó.
 *
 * Las dos cosas van en la misma pantalla a propósito: el historial de envíos es lo
 * que se consulta cuando la obra social dice "no nos llegó nada", y la pregunta
 * siempre arranca por el contacto ("¿qué le mandé a OSDE?"). Una pantalla aparte
 * sería otra entrada en un menú que ya tiene muchas.
 */
class ContactoController extends Controller
{
    /** Cuántos envíos se muestran: los últimos, que son los que se reclaman. */
    private const ENVIOS_EN_PANTALLA = 30;

    public function index(Request $peticion): Response
    {
        Gate::authorize('viewAny', Contacto::class);

        /** @var User $usuario */
        $usuario = $peticion->user();

        $contactos = $usuario->contactos()
            ->get()
            /*
             * El orden va en PHP: `nombre` está cifrado, y un `orderBy` sobre el
             * ciphertext ordenaría al azar (ver `ConsultaVigilada`).
             */
            ->sortBy(fn (Contacto $contacto): string => mb_strtolower($contacto->nombre))
            ->values()
            ->map(fn (Contacto $contacto): array => [
                'id' => $contacto->id,
                'nombre' => $contacto->nombre,
                'email' => $contacto->email,
                'tipo' => $contacto->tipo->value,
                'tipoEtiqueta' => $contacto->tipo->etiqueta(),
            ])
            ->all();

        $envios = $usuario->envios()
            // Explícito: sin esto es una consulta por envío para sus archivos.
            ->with('archivos')
            ->latest()
            ->limit(self::ENVIOS_EN_PANTALLA)
            ->get()
            ->map(fn (Envio $envio): array => [
                'id' => $envio->id,
                'destinatario' => $envio->destinatario,
                'destinatarioNombre' => $envio->destinatario_nombre,
                'asunto' => $envio->asunto,
                'cuandoVisible' => $usuario->enSuZona($envio->created_at)?->format('d/m/Y H:i'),
                'estado' => $envio->estado->value,
                'estadoEtiqueta' => $envio->estado->etiqueta(),
                'archivos' => $envio->archivos
                    ->map(fn (ArchivoEnviado $archivo): string => $archivo->nombre)
                    ->all(),
            ])
            ->all();

        return Inertia::render('contactos/Index', [
            'contactos' => $contactos,
            'envios' => $envios,
            'tipos' => array_map(
                fn (TipoContacto $tipo): array => ['valor' => $tipo->value, 'etiqueta' => $tipo->etiqueta()],
                TipoContacto::cases(),
            ),
            'precarga' => $this->precargaDesdeCobertura($peticion, $usuario),
        ]);
    }

    public function store(ContactoGuardarRequest $peticion): RedirectResponse
    {
        Gate::authorize('create', Contacto::class);

        // Por la relación: `usuario_id` no es fillable.
        $contacto = $peticion->user()->contactos()->create($peticion->validated());

        /*
         * `back()` y no la libreta: el alta también se hace desde el armado de un
         * envío -"no tengo a la farmacia cargada"-, y ahí hay que volver al
         * envío, con los documentos que ya estaban elegidos.
         */
        return back()->with('exito', "Se agregó a {$contacto->nombre}.");
    }

    public function update(ContactoGuardarRequest $peticion, Contacto $contacto): RedirectResponse
    {
        Gate::authorize('update', $contacto);

        $contacto->update($peticion->validated());

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    /**
     * Borra de verdad: sin soft deletes (ver la migración). Lo que ya se le mandó
     * no se pierde, porque el historial guarda su propia foto de la dirección.
     */
    public function destroy(Contacto $contacto): RedirectResponse
    {
        Gate::authorize('delete', $contacto);

        $contacto->delete();

        return back()->with('exito', 'Se borró el contacto. Lo que ya le mandaste sigue en el historial.');
    }

    /**
     * "Pueden salir de una cobertura", como pide el plan.
     *
     * Una cobertura no tiene mail —tiene teléfono y sitio web—, así que lo que sale
     * de ella es el nombre de la entidad y el tipo; la dirección la tiene que poner
     * la persona, que es justamente lo que no conviene adivinar.
     *
     * ⚠️ Una cobertura que esta persona no puede ver se ignora en silencio, sin un
     * 403: con un 403 la pantalla confirmaría que ese id existe en la ficha de
     * otro.
     *
     * @return array{nombre: string, tipo: string}|null
     */
    private function precargaDesdeCobertura(Request $peticion, User $usuario): ?array
    {
        $id = $peticion->integer('cobertura');

        if ($id === 0) {
            return null;
        }

        $cobertura = Cobertura::query()->find($id);

        if (! $cobertura instanceof Cobertura || ! Gate::forUser($usuario)->allows('view', $cobertura)) {
            return null;
        }

        return [
            'nombre' => $cobertura->entidad,
            'tipo' => TipoContacto::ObraSocial->value,
        ];
    }
}
