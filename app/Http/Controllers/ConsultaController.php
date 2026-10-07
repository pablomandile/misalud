<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoAdjunto;
use App\Http\Requests\ConsultaGuardarRequest;
use App\Models\Adjunto;
use App\Models\Centro;
use App\Models\Consulta;
use App\Models\Enfermedad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Turno;
use App\Models\User;
use App\Services\ArchivoService;
use App\Support\CatalogoVisible;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las consultas de un paciente —la visita al médico y lo que se dijo— y sus
 * grabaciones.
 */
class ConsultaController extends Controller
{
    public function index(Paciente $paciente): Response
    {
        Gate::authorize('view', $paciente);

        /** @var User $usuario */
        $usuario = auth()->user();

        $consultas = $paciente->consultas()
            // Explícito: sin esto es una consulta por fila para cada relación.
            ->with(['medico', 'centro', 'enfermedad', 'adjuntos'])
            // En claro, así que el orden puede ser de SQL. La más reciente arriba:
            // muestra historia, no agenda.
            ->orderByDesc('fecha_hora')
            ->get();

        return Inertia::render('consultas/Index', [
            'paciente' => $this->datosDelPaciente($paciente, $usuario),
            'consultas' => $consultas->map(fn (Consulta $c): array => $this->serializar($c, $usuario))->all(),
            'medicos' => $this->catalogo($paciente, Medico::class, 'medico_id'),
            'centros' => $this->catalogo($paciente, Centro::class, 'centro_id'),
            'enfermedades' => $this->enfermedadesDeLaFicha($paciente),
            'turnos' => $this->turnosDeLaFicha($paciente, $usuario),
            // La precarga sale del SERVIDOR, no de `new Date()` (ver "Fechas").
            'ahoraLocal' => $usuario->ahora()->format('Y-m-d\TH:i'),
            'maximoAudioMb' => (int) (ArchivoService::MAXIMO_AUDIO_BYTES / 1024 / 1024),
        ]);
    }

    /**
     * "Grabaciones": una vista sobre los audios de las consultas, por fecha.
     *
     * **No es una tabla**: duplicarla dejaría el mismo archivo listado en dos
     * lados y desincronizado en cuanto alguien borre una consulta. Sale de los
     * adjuntos de las consultas de la ficha, por la relación —así lo que está
     * en la papelera queda afuera solo—.
     */
    public function grabaciones(Paciente $paciente): Response
    {
        Gate::authorize('view', $paciente);

        /** @var User $usuario */
        $usuario = auth()->user();

        $grabaciones = $paciente->consultas()
            ->with(['medico', 'adjuntos' => fn ($q) => $q->where('tipo', TipoAdjunto::AudioConsulta->value)])
            ->orderByDesc('fecha_hora')
            ->get()
            ->flatMap(fn (Consulta $consulta) => $consulta->adjuntos->map(
                fn (Adjunto $audio): array => [
                    ...$this->serializarAudio($audio),
                    'consultaId' => $consulta->id,
                    'consultaVisible' => $this->titulo($consulta, $usuario),
                ],
            ))
            ->values()
            ->all();

        return Inertia::render('consultas/Grabaciones', [
            'paciente' => $this->datosDelPaciente($paciente, $usuario),
            'grabaciones' => $grabaciones,
        ]);
    }

    public function store(ConsultaGuardarRequest $peticion, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('crearEn', [Consulta::class, $paciente]);

        $paciente->consultas()->create([
            ...$peticion->safe(['medico_id', 'centro_id', 'enfermedad_id', 'turno_id', 'motivo', 'notas']),
            'fecha_hora' => $peticion->fechaHoraEnUtc(),
        ]);

        return back()->with('exito', 'Se agregó la consulta.');
    }

    public function update(ConsultaGuardarRequest $peticion, Consulta $consulta): RedirectResponse
    {
        Gate::authorize('update', $consulta);

        $consulta->update([
            ...$peticion->safe(['medico_id', 'centro_id', 'enfermedad_id', 'turno_id', 'motivo', 'notas']),
            'fecha_hora' => $peticion->fechaHoraEnUtc(),
        ]);

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    /**
     * La consulta va a la papelera, como cualquier registro clínico. **Sus
     * grabaciones se borran del disco**, igual que cuando se borra un documento
     * suelto: van sin cifrar, y un audio que queda en el disco después de que
     * alguien lo "borró" es justo lo que esa persona no espera.
     */
    public function destroy(Consulta $consulta, ArchivoService $archivos): RedirectResponse
    {
        Gate::authorize('delete', $consulta);

        foreach ($consulta->adjuntos as $adjunto) {
            // Primero el disco, después la fila.
            $archivos->borrar($adjunto);
            $adjunto->delete();
        }

        $consulta->delete();

        return back()->with('exito', 'Se eliminó la consulta.');
    }

    /**
     * @return array{id: int, nombre: string, puedeEditar: bool}
     */
    private function datosDelPaciente(Paciente $paciente, User $usuario): array
    {
        return [
            'id' => $paciente->id,
            'nombre' => $paciente->nombre,
            'puedeEditar' => $paciente->rolDe($usuario)?->puedeEditar() ?? false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(Consulta $consulta, User $usuario): array
    {
        $enSuZona = $usuario->enSuZona($consulta->fecha_hora) ?? $consulta->fecha_hora;

        return [
            'id' => $consulta->id,
            'titulo' => $this->titulo($consulta, $usuario),
            'fechaVisible' => $enSuZona->format('d/m/Y \a \l\a\s H:i'),
            'fechaLocal' => $enSuZona->format('Y-m-d\TH:i'),
            'medico_id' => $consulta->medico_id,
            'medicoNombre' => $consulta->medico?->nombre,
            'centro_id' => $consulta->centro_id,
            'centroNombre' => $consulta->centro?->nombre,
            'enfermedad_id' => $consulta->enfermedad_id,
            'enfermedadNombre' => $consulta->enfermedad?->nombre,
            'turno_id' => $consulta->turno_id,
            'motivo' => $consulta->motivo,
            'notas' => $consulta->notas,
            'audios' => $consulta->adjuntos
                ->where('tipo', TipoAdjunto::AudioConsulta)
                ->sortBy('created_at')
                ->values()
                ->map(fn (Adjunto $a): array => $this->serializarAudio($a))
                ->all(),
        ];
    }

    /**
     * "Dr. Pérez · 15/03/2026": lo que muestra el reproductor y la pantalla
     * bloqueada para saber qué se está escuchando.
     */
    private function titulo(Consulta $consulta, User $usuario): string
    {
        $fecha = ($usuario->enSuZona($consulta->fecha_hora) ?? $consulta->fecha_hora)->format('d/m/Y');
        $quien = $consulta->medico->nombre ?? $consulta->motivo ?? 'Consulta';

        return "{$quien} · {$fecha}";
    }

    /**
     * @return array{id: int, nombre: string, mime: string, tamanio: int, duracion: int|null, url: string}
     */
    private function serializarAudio(Adjunto $audio): array
    {
        return [
            'id' => $audio->id,
            'nombre' => $audio->nombre_original,
            'mime' => $audio->mime,
            'tamanio' => $audio->tamanio_bytes,
            'duracion' => $audio->duracion_segundos,
            'url' => route('adjuntos.show', $audio),
        ];
    }

    /**
     * Mismo criterio que valida el FormRequest: propio, semilla o ya usado en
     * esta ficha.
     *
     * @param  class-string<Medico|Centro>  $modelo
     * @return list<array{id: int, nombre: string}>
     */
    private function catalogo(Paciente $paciente, string $modelo, string $columna): array
    {
        $yaUsados = $paciente->consultas()->whereNotNull($columna)->pluck($columna)->unique()->all();

        return array_values($modelo::query()
            ->where(fn ($consulta) => $consulta
                ->where(CatalogoVisible::para(auth()->id()))
                ->orWhereIn('id', $yaUsados)
            )
            ->get()
            ->map(fn (Model $r): array => ['id' => (int) $r->getKey(), 'nombre' => (string) $r->getAttribute('nombre')])
            ->sortBy(fn (array $o): string => mb_strtolower($o['nombre']))
            ->values()
            ->all());
    }

    /**
     * @return list<array{id: int, nombre: string}>
     */
    private function enfermedadesDeLaFicha(Paciente $paciente): array
    {
        return array_values($paciente->enfermedades()
            ->get()
            ->map(fn (Enfermedad $e): array => ['id' => $e->id, 'nombre' => $e->nombre])
            ->sortBy(fn (array $o): string => mb_strtolower($o['nombre']))
            ->values()
            ->all());
    }

    /**
     * Los turnos de la ficha, el más reciente primero: la consulta suele ser la
     * de un turno que ya pasó.
     *
     * @return list<array{id: int, nombre: string}>
     */
    private function turnosDeLaFicha(Paciente $paciente, User $usuario): array
    {
        /** @var Collection<int, Turno> $turnos */
        $turnos = $paciente->turnos()->orderByDesc('fecha_hora')->get();

        return array_values($turnos
            ->map(fn (Turno $t): array => [
                'id' => $t->id,
                'nombre' => ($usuario->enSuZona($t->fecha_hora) ?? $t->fecha_hora)->format('d/m/Y H:i')
                    .($t->motivo ? ' · '.$t->motivo : ''),
            ])
            ->all());
    }
}
