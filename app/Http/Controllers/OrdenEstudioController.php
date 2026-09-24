<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\OrdenEstudioGuardarRequest;
use App\Models\Adjunto;
use App\Models\Medico;
use App\Models\OrdenEstudio;
use App\Models\Paciente;
use App\Support\CatalogoVisible;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las órdenes de estudio de un paciente: el papel que da el médico antes.
 *
 * La pantalla existe para una sola pregunta —**qué me falta hacerme**—, así
 * que lo pendiente va primero y todo lo demás después. Una orden cargada
 * que no se puede consultar de un vistazo no sirve para nada.
 */
class OrdenEstudioController extends Controller
{
    public function index(Paciente $paciente): Response
    {
        Gate::authorize('view', $paciente);

        $usuario = auth()->user();

        $ordenes = $paciente->ordenesEstudio()
            // Explícito: sin esto es una consulta por cada fila del listado.
            ->with(['medico', 'adjuntos'])
            ->get()
            /*
             * Pendientes primero y, dentro de cada grupo, la más reciente.
             * `estado` y `fecha` están en claro, así que esto podría ir en
             * SQL; se hace acá porque las filas ya están traídas y el
             * criterio compuesto se lee mejor de una sola vez.
             */
            ->sortByDesc(fn (OrdenEstudio $orden): string => ($orden->estado->estaPendiente() ? '1' : '0')
                .$orden->fecha->format('Ymd'))
            ->values()
            ->map(fn (OrdenEstudio $orden): array => $this->serializar($orden))
            ->all();

        return Inertia::render('ordenes/Index', [
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
                'puedeEditar' => $paciente->rolDe($usuario)?->puedeEditar() ?? false,
            ],
            'ordenes' => $ordenes,
            'medicos' => $this->medicosDisponibles($paciente),
            'hoy' => $usuario?->hoyCalendario()->format('Y-m-d'),
        ]);
    }

    public function store(OrdenEstudioGuardarRequest $peticion, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('crearEn', [OrdenEstudio::class, $paciente]);

        $paciente->ordenesEstudio()->create($peticion->validated());

        return back()->with('exito', 'Se agregó la orden.');
    }

    public function update(OrdenEstudioGuardarRequest $peticion, OrdenEstudio $orden): RedirectResponse
    {
        Gate::authorize('update', $orden);

        $orden->update($peticion->validated());

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(OrdenEstudio $orden): RedirectResponse
    {
        Gate::authorize('delete', $orden);

        $orden->delete();

        return back()->with('exito', 'Se eliminó la orden.');
    }

    /**
     * Mismo criterio que enfermedades y tratamientos: lo que esta persona
     * puede ver -propio o semilla- más lo que esta ficha ya viene usando.
     * Tiene que coincidir con lo que valida el FormRequest.
     *
     * @return list<array<string, mixed>>
     */
    private function medicosDisponibles(Paciente $paciente): array
    {
        $yaUsados = $paciente->ordenesEstudio()
            ->whereNotNull('medico_id')
            ->pluck('medico_id')
            ->unique()
            ->all();

        return array_values(Medico::query()
            ->where(fn ($consulta) => $consulta
                ->where(CatalogoVisible::para(auth()->id()))
                ->orWhereIn('id', $yaUsados)
            )
            ->get()
            // En PHP: `nombre` está cifrado.
            ->sortBy(fn (Medico $medico): string => mb_strtolower($medico->nombre))
            ->values()
            ->map(fn (Medico $medico): array => [
                'id' => $medico->id,
                'nombre' => $medico->nombre,
            ])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(OrdenEstudio $orden): array
    {
        return [
            'id' => $orden->id,
            'estudio_solicitado' => $orden->estudio_solicitado,
            'fecha' => $orden->fecha->format('Y-m-d'),
            'fechaVisible' => $orden->fecha->format('d/m/Y'),
            'estado' => $orden->estado->value,
            'estadoEtiqueta' => $orden->estado->etiqueta(),
            'estaPendiente' => $orden->estado->estaPendiente(),
            'medico_id' => $orden->medico_id,
            'medicoNombre' => $orden->medico?->nombre,
            'notas' => $orden->notas,

            /*
             * El papel, fotografiado o en PDF. Va por `adjuntos.show` -la
             * ruta cacheable es solo para la credencial-.
             */
            'adjuntos' => $orden->adjuntos
                ->sortByDesc('created_at')
                ->values()
                ->map(fn (Adjunto $adjunto): array => [
                    'id' => $adjunto->id,
                    'nombre' => $adjunto->nombre_original,
                    'mime' => $adjunto->mime,
                    'tamanio' => $adjunto->tamanio_bytes,
                    'url' => route('adjuntos.show', $adjunto),
                ])
                ->all(),
        ];
    }
}
