<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoOrdenEstudio;
use App\Http\Requests\EstudioGuardarRequest;
use App\Models\Adjunto;
use App\Models\Centro;
use App\Models\Enfermedad;
use App\Models\Estudio;
use App\Models\Medico;
use App\Models\OrdenEstudio;
use App\Models\Paciente;
use App\Models\ResultadoEstudio;
use App\Services\SeriesDeResultados;
use App\Support\CatalogoVisible;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los estudios de un paciente: lo que se hizo, con sus parámetros y su
 * informe.
 *
 * Los resultados (Etapa 9.3) viajan como prop anidado de esta misma
 * pantalla -no tienen `index` propio-, mismo patrón que la bitácora de una
 * enfermedad: son pocas filas por estudio y se consultan siempre junto a
 * él, nunca sueltas.
 */
class EstudioController extends Controller
{
    public function __construct(private readonly SeriesDeResultados $series) {}

    public function index(Paciente $paciente): Response
    {
        Gate::authorize('view', $paciente);

        $usuario = auth()->user();

        $estudios = $paciente->estudios()
            // Explícito: sin esto es una consulta por cada fila del listado.
            ->with(['medico', 'centro', 'resultados', 'adjuntos'])
            ->get()
            ->sortByDesc(fn (Estudio $estudio): string => $estudio->fecha->format('Ymd'))
            ->values()
            ->map(fn (Estudio $estudio): array => $this->serializar($estudio))
            ->all();

        return Inertia::render('estudios/Index', [
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
                'puedeEditar' => $paciente->rolDe($usuario)?->puedeEditar() ?? false,
            ],
            'estudios' => $estudios,
            'medicos' => $this->medicosDisponibles($paciente),
            'centros' => $this->centrosDisponibles($paciente),
            'enfermedades' => $this->enfermedadesDeLaFicha($paciente),
            'ordenesDisponibles' => $this->ordenesDisponibles($paciente),
            'evolucion' => $this->series->armar($this->resultadosDeLaFicha($paciente)),
            'hoy' => $usuario?->hoyCalendario()->format('Y-m-d'),
        ]);
    }

    public function store(EstudioGuardarRequest $peticion, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('crearEn', [Estudio::class, $paciente]);

        $estudio = $paciente->estudios()->create(
            $peticion->safe(['medico_id', 'centro_id', 'enfermedad_id', 'tipo', 'fecha', 'notas']),
        );

        /*
         * Cerrar el circuito orden → estudio: vincular implica `Hecha`. La
         * validación ya garantizó que la orden es de este mismo paciente y
         * que no estaba vinculada a otro estudio (ver `EstudioGuardarRequest`),
         * así que acá no hace falta un `Gate::authorize` aparte -quien puede
         * crear un estudio en esta ficha ya tiene el mismo nivel de acceso
         * sobre cualquier registro de la misma ficha-.
         */
        $ordenIdValidado = $peticion->validated('orden_estudio_id');
        $ordenId = is_numeric($ordenIdValidado) ? (int) $ordenIdValidado : null;

        if ($ordenId !== null) {
            $orden = OrdenEstudio::find($ordenId);

            /*
             * `setAttribute()` y no `update(['estudio_id' => ...])`:
             * `estudio_id` NO es fillable -no lo escribe un formulario de
             * la orden-, así que un `update()` masivo lo ignoraría en
             * silencio y dejaría la orden sin vincular sin ningún error.
             * Mismo camino que usa `CatalogoBaseController` para
             * `usuario_id`.
             */
            $orden?->setAttribute('estudio_id', $estudio->id);
            $orden?->setAttribute('estado', EstadoOrdenEstudio::Hecha);
            $orden?->save();
        }

        return back()->with('exito', 'Se agregó el estudio.');
    }

    public function update(EstudioGuardarRequest $peticion, Estudio $estudio): RedirectResponse
    {
        Gate::authorize('update', $estudio);

        $estudio->update(
            $peticion->safe(['medico_id', 'centro_id', 'enfermedad_id', 'tipo', 'fecha', 'notas']),
        );

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(Estudio $estudio): RedirectResponse
    {
        Gate::authorize('delete', $estudio);

        $estudio->delete();

        return back()->with('exito', 'Se eliminó el estudio.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function medicosDisponibles(Paciente $paciente): array
    {
        $yaUsados = $paciente->estudios()->whereNotNull('medico_id')->pluck('medico_id')->unique()->all();

        return array_values(Medico::query()
            ->where(fn ($consulta) => $consulta
                ->where(CatalogoVisible::para(auth()->id()))
                ->orWhereIn('id', $yaUsados)
            )
            ->get()
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
        $yaUsados = $paciente->estudios()->whereNotNull('centro_id')->pluck('centro_id')->unique()->all();

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
     * @return list<array<string, mixed>>
     */
    private function enfermedadesDeLaFicha(Paciente $paciente): array
    {
        return array_values($paciente->enfermedades()
            ->get()
            ->sortBy(fn (Enfermedad $enfermedad): string => mb_strtolower($enfermedad->nombre))
            ->values()
            ->map(fn (Enfermedad $enfermedad): array => ['id' => $enfermedad->id, 'nombre' => $enfermedad->nombre])
            ->all());
    }

    /**
     * Las órdenes pendientes de vincular: de esta ficha y todavía sin un
     * estudio. Una vez usada, una orden deja de aparecer acá -no se puede
     * vincular dos veces-.
     *
     * @return list<array<string, mixed>>
     */
    private function ordenesDisponibles(Paciente $paciente): array
    {
        return array_values($paciente->ordenesEstudio()
            ->whereNull('estudio_id')
            ->get()
            ->sortByDesc(fn (OrdenEstudio $orden): string => $orden->fecha->format('Ymd'))
            ->map(fn (OrdenEstudio $orden): array => [
                'id' => $orden->id,
                'estudio_solicitado' => $orden->estudio_solicitado,
                'fechaVisible' => $orden->fecha->format('d/m/Y'),
            ])
            ->all());
    }

    /**
     * Todos los resultados de la ficha, de cualquier estudio, con su
     * `estudio` ya cargado -es de ahí de donde sale la fecha de cada punto,
     * porque un resultado no tiene fecha propia-.
     *
     * Consulta aparte y no `$estudios->pluck('resultados')`: eager-loader
     * una relación no carga la inversa, así que `$resultado->estudio`
     * saldría en blanco -y es justo el dato que arma la línea de tiempo-.
     *
     * @return Collection<int, ResultadoEstudio>
     */
    private function resultadosDeLaFicha(Paciente $paciente): Collection
    {
        return ResultadoEstudio::query()
            ->whereHas('estudio', fn ($consulta) => $consulta->where('paciente_id', $paciente->id))
            ->with('estudio')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(Estudio $estudio): array
    {
        return [
            'id' => $estudio->id,
            'tipo' => $estudio->tipo,
            'fecha' => $estudio->fecha->format('Y-m-d'),
            'fechaVisible' => $estudio->fecha->format('d/m/Y'),
            'medico_id' => $estudio->medico_id,
            'medicoNombre' => $estudio->medico?->nombre,
            'centro_id' => $estudio->centro_id,
            'centroNombre' => $estudio->centro?->nombre,
            'enfermedad_id' => $estudio->enfermedad_id,
            'enfermedadNombre' => $estudio->enfermedad?->nombre,
            'notas' => $estudio->notas,

            'resultados' => $estudio->resultados
                ->sortBy(fn (ResultadoEstudio $r): string => mb_strtolower($r->parametro))
                ->values()
                ->map(fn (ResultadoEstudio $r): array => [
                    'id' => $r->id,
                    'parametro' => $r->parametro,
                    'valor' => $r->valor,
                    'unidad' => $r->unidad,
                    'rango_referencia' => $r->rango_referencia,
                ])
                ->all(),

            // El informe o la imagen cruda: van por `adjuntos.show` como
            // cualquier otro documento -la ruta cacheable es solo para la
            // credencial-.
            'adjuntos' => $estudio->adjuntos
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
