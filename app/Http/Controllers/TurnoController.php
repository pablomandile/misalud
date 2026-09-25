<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TurnoGuardarRequest;
use App\Models\Centro;
use App\Models\Medico;
use App\Models\OrdenEstudio;
use App\Models\Paciente;
use App\Models\Recordatorio;
use App\Models\Turno;
use App\Models\User;
use App\Support\CatalogoVisible;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La agenda de un paciente: los turnos y los avisos que se desprenden de ellos.
 *
 * La pantalla contesta **una sola pregunta: qué tengo por delante.** Por eso
 * lo que viene va arriba y ordenado del más próximo al más lejano —al revés
 * que todos los otros listados de la app, que van del más reciente al más
 * viejo—: en una agenda, lo inminente es lo que importa, y un turno de
 * mañana no puede quedar debajo de uno de diciembre.
 */
class TurnoController extends Controller
{
    public function index(Paciente $paciente): Response
    {
        Gate::authorize('view', $paciente);

        $usuario = auth()->user();
        $ahora = now();

        $turnos = $paciente->turnos()
            // Explícito: sin esto es una consulta por cada fila del listado.
            ->with(['medico', 'centro', 'ordenEstudio', 'recordatorios'])
            ->get();

        return Inertia::render('turnos/Index', [
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
                'puedeEditar' => $paciente->rolDe($usuario)?->puedeEditar() ?? false,
            ],

            /*
             * Separados en el servidor y no en el cliente: "qué viene" depende
             * de la hora, y la hora la sabe el servidor -el reloj del
             * navegador puede estar corrido, y con él la mitad de la agenda-.
             */
            'proximos' => $turnos
                ->filter(fn (Turno $turno): bool => $turno->fecha_hora->greaterThanOrEqualTo($ahora))
                ->sortBy(fn (Turno $turno): string => $turno->fecha_hora->format('YmdHi'))
                ->values()
                ->map(fn (Turno $turno): array => $this->serializar($turno, $usuario))
                ->all(),

            'pasados' => $turnos
                ->filter(fn (Turno $turno): bool => $turno->fecha_hora->lessThan($ahora))
                ->sortByDesc(fn (Turno $turno): string => $turno->fecha_hora->format('YmdHi'))
                ->values()
                ->map(fn (Turno $turno): array => $this->serializar($turno, $usuario))
                ->all(),

            'recordatorios' => $this->recordatoriosAbiertos($paciente, $usuario),

            'medicos' => $this->medicosDisponibles($paciente),
            'centros' => $this->centrosDisponibles($paciente),
            'ordenesDisponibles' => $this->ordenesDisponibles($paciente),

            // La precarga del formulario sale del SERVIDOR y no de `new
            // Date()`: si el celular está en otra zona que la cuenta, el
            // navegador escribiría una hora que el servidor reinterpreta.
            'ahoraLocal' => $usuario?->ahora()->format('Y-m-d\TH:i'),
        ]);
    }

    public function store(TurnoGuardarRequest $peticion, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('crearEn', [Turno::class, $paciente]);

        $paciente->turnos()->create([
            ...$peticion->safe(['medico_id', 'centro_id', 'orden_estudio_id', 'motivo', 'estado']),
            'fecha_hora' => $peticion->fechaHoraEnUtc(),
        ]);

        return back()->with('exito', 'Se agendó el turno.');
    }

    public function update(TurnoGuardarRequest $peticion, Turno $turno): RedirectResponse
    {
        Gate::authorize('update', $turno);

        $turno->update([
            ...$peticion->safe(['medico_id', 'centro_id', 'orden_estudio_id', 'motivo', 'estado']),
            'fecha_hora' => $peticion->fechaHoraEnUtc(),
        ]);

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(Turno $turno): RedirectResponse
    {
        Gate::authorize('delete', $turno);

        $turno->delete();

        return back()->with('exito', 'Se eliminó el turno.');
    }

    /**
     * Los avisos que todavía le quedan por resolver a la persona.
     *
     * Incluye `Enviado`: que el mail ya haya salido no significa que esté
     * resuelto —ver `EstadoRecordatorio`—. Lo que no se muestra es lo
     * completado ni lo vencido.
     *
     * @return list<array<string, mixed>>
     */
    private function recordatoriosAbiertos(Paciente $paciente, ?User $usuario): array
    {
        return array_values($paciente->recordatorios()
            ->whereIn('estado', ['pendiente', 'enviado'])
            ->orderBy('fecha')
            ->get()
            ->map(function (Recordatorio $recordatorio) use ($usuario): array {
                $enSuZona = $usuario?->enSuZona($recordatorio->fecha) ?? $recordatorio->fecha;

                return [
                    'id' => $recordatorio->id,
                    'tipo' => $recordatorio->tipo->value,
                    'tipoEtiqueta' => $recordatorio->tipo->etiqueta(),
                    'estado' => $recordatorio->estado->value,
                    'estadoEtiqueta' => $recordatorio->estado->etiqueta(),
                    'fechaVisible' => $enSuZona->format('d/m/Y H:i'),
                    'yaCorresponde' => $recordatorio->fecha->lessThanOrEqualTo(now()),
                ];
            })
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function medicosDisponibles(Paciente $paciente): array
    {
        $yaUsados = $paciente->turnos()->whereNotNull('medico_id')->pluck('medico_id')->unique()->all();

        return array_values(Medico::query()
            ->where(fn ($consulta) => $consulta
                ->where(CatalogoVisible::para(auth()->id()))
                ->orWhereIn('id', $yaUsados)
            )
            ->get()
            // En PHP: `nombre` está cifrado.
            ->sortBy(fn (Medico $medico): string => mb_strtolower($medico->nombre))
            ->values()
            ->map(fn (Medico $medico): array => ['id' => $medico->id, 'nombre' => $medico->nombre])
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function centrosDisponibles(Paciente $paciente): array
    {
        $yaUsados = $paciente->turnos()->whereNotNull('centro_id')->pluck('centro_id')->unique()->all();

        return array_values(Centro::query()
            ->where(fn ($consulta) => $consulta
                ->where(CatalogoVisible::para(auth()->id()))
                ->orWhereIn('id', $yaUsados)
            )
            ->get()
            ->sortBy(fn (Centro $centro): string => mb_strtolower($centro->nombre))
            ->values()
            ->map(fn (Centro $centro): array => ['id' => $centro->id, 'nombre' => $centro->nombre])
            ->all());
    }

    /**
     * Las órdenes de esta ficha, para poder colgarle el turno a la que lo
     * motivó. Se ofrecen **todas**, no solo las pendientes: ver el
     * comentario de la regla en `TurnoGuardarRequest`.
     *
     * @return list<array<string, mixed>>
     */
    private function ordenesDisponibles(Paciente $paciente): array
    {
        return array_values($paciente->ordenesEstudio()
            ->get()
            ->sortByDesc(fn (OrdenEstudio $orden): string => $orden->fecha->format('Ymd'))
            ->values()
            ->map(fn (OrdenEstudio $orden): array => [
                'id' => $orden->id,
                'estudio_solicitado' => $orden->estudio_solicitado,
                'fechaVisible' => $orden->fecha->format('d/m/Y'),
            ])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(Turno $turno, ?User $usuario): array
    {
        $enSuZona = $usuario?->enSuZona($turno->fecha_hora) ?? $turno->fecha_hora;

        return [
            'id' => $turno->id,
            'estado' => $turno->estado->value,
            'estadoEtiqueta' => $turno->estado->etiqueta(),
            'estaVigente' => $turno->estado->estaVigente(),

            /*
             * Tres formas de la misma fecha, cada una para algo distinto: la
             * legible para mostrar, la del `datetime-local` para editar, y el
             * ISO para ordenar en el cliente. Las tres salen de la zona de
             * quien mira, no de UTC. Mismo reparto que una medición.
             */
            'fechaVisible' => $enSuZona->format('d/m/Y H:i'),
            'fechaLocal' => $enSuZona->format('Y-m-d\TH:i'),
            'fechaIso' => $enSuZona->toIso8601String(),

            'medico_id' => $turno->medico_id,
            'medicoNombre' => $turno->medico?->nombre,
            'centro_id' => $turno->centro_id,
            'centroNombre' => $turno->centro?->nombre,
            'orden_estudio_id' => $turno->orden_estudio_id,
            'ordenSolicitado' => $turno->ordenEstudio?->estudio_solicitado,
            'motivo' => $turno->motivo,

            // Para que la pantalla pueda mostrar que el aviso existe sin
            // tener que cruzar nada del lado del cliente.
            'tieneRecordatorio' => $turno->recordatorios->isNotEmpty(),
        ];
    }
}
