<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PeriodicidadToma;
use App\Enums\TipoAdjunto;
use App\Http\Requests\TratamientoGuardarRequest;
use App\Models\Adjunto;
use App\Models\Enfermedad;
use App\Models\Medicamento;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Tratamiento;
use App\Models\User;
use App\Services\AdjuntadorDeArchivos;
use App\Services\PacientePrincipal;
use App\Support\CatalogoVisible;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los tratamientos de un paciente: qué medicamento toma, con qué dosis. En
 * pantalla se llaman «Medicamentos» (decisión del usuario): es lo que toma
 * cada persona, con su toma y las fotos de la caja.
 */
class TratamientoController extends Controller
{
    public function __construct(private AdjuntadorDeArchivos $adjuntador) {}

    /**
     * «Medicamentos» del menú: los del paciente principal. Sin ninguna ficha,
     * a Pacientes, a cargar una.
     */
    public function principal(): RedirectResponse
    {
        $usuario = auth()->user();
        assert($usuario instanceof User);

        $paciente = PacientePrincipal::de($usuario);

        return $paciente === null
            ? to_route('pacientes.index')->with('error', 'Primero cargá una ficha para anotar sus medicamentos.')
            : to_route('pacientes.tratamientos.index', $paciente);
    }

    public function index(Paciente $paciente): Response
    {
        Gate::authorize('view', $paciente);

        $usuario = auth()->user();

        $tratamientos = $paciente->tratamientos()
            // Explícito: sin esto es una consulta por cada fila del listado.
            ->with(['medicamento', 'medico', 'enfermedad', 'adjuntos'])
            ->get()
            /*
             * Activos primero y, dentro de cada grupo, el más nuevo. En PHP:
             * el orden real depende de `activo` (en claro) y de `inicio`
             * (también en claro, así que esto podría ir en SQL, pero ya se
             * trajeron todas las filas y separar el criterio acá es una
             * línea en vez de dos consultas).
             */
            ->sortByDesc(fn (Tratamiento $t): string => ($t->activo ? '1' : '0').$t->inicio->format('Ymd'))
            ->values()
            ->map(fn (Tratamiento $t): array => $this->serializar($t))
            ->all();

        return Inertia::render('tratamientos/Index', [
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
                'puedeEditar' => $paciente->rolDe($usuario)?->puedeEditar() ?? false,
            ],
            'fichas' => $usuario instanceof User ? PacientePrincipal::opciones($usuario) : [],
            'periodicidades' => PeriodicidadToma::opciones(),
            'diasDeLaSemana' => PeriodicidadToma::opcionesDeDias(),
            'tratamientos' => $tratamientos,
            'medicamentos' => $this->medicamentosDisponibles($paciente),
            'medicos' => $this->medicosDisponibles($paciente),
            'enfermedades' => $this->enfermedadesDeLaFicha($paciente),
            'hoy' => $usuario?->hoyCalendario()->format('Y-m-d'),
        ]);
    }

    public function store(TratamientoGuardarRequest $peticion, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('crearEn', [Tratamiento::class, $paciente]);

        // El medicamento (si hay que crearlo), el registro y las fotos de la
        // caja: juntos o nada.
        DB::transaction(function () use ($peticion, $paciente): void {
            $tratamiento = $paciente->tratamientos()->create([
                ...$this->datos($peticion),
                'activo' => $peticion->boolean('activo', true),
            ]);

            $this->adjuntador->adjuntar($tratamiento, $peticion->file('archivos', []), TipoAdjunto::CajaMedicamento);
        });

        return back()->with('exito', 'Se agregó el medicamento.');
    }

    public function update(TratamientoGuardarRequest $peticion, Tratamiento $tratamiento): RedirectResponse
    {
        Gate::authorize('update', $tratamiento);

        DB::transaction(fn () => $tratamiento->update([
            ...$this->datos($peticion),
            'activo' => $peticion->boolean('activo'),
        ]));

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(Tratamiento $tratamiento): RedirectResponse
    {
        Gate::authorize('delete', $tratamiento);

        $tratamiento->delete();

        return back()->with('exito', 'Se eliminó el medicamento.');
    }

    /**
     * Lo validado, con el `medicamento_id` resuelto: si el nombre escrito no
     * coincidió con ninguno de la lista (ver el FormRequest), se crea en el
     * catálogo propio de quien lo carga.
     *
     * @return array<string, mixed>
     */
    private function datos(TratamientoGuardarRequest $peticion): array
    {
        $datos = $peticion->safe()->except(['archivos', 'medicamento_nombre']);

        if (empty($datos['medicamento_id'])) {
            $medicamento = new Medicamento(['nombre_comercial' => $peticion->string('medicamento_nombre')->toString()]);
            $medicamento->setAttribute('usuario_id', auth()->id());
            $medicamento->save();

            $datos['medicamento_id'] = $medicamento->id;
        }

        return $datos;
    }

    /**
     * Igual criterio que médicos y enfermedades: lo que esta persona puede
     * ver -propio o semilla- más lo que esta ficha ya viene usando. Mismo
     * criterio que valida `TratamientoGuardarRequest`.
     *
     * @return list<array<string, mixed>>
     */
    private function medicamentosDisponibles(Paciente $paciente): array
    {
        $yaUsados = $paciente->tratamientos()->pluck('medicamento_id')->unique()->all();

        return array_values(Medicamento::query()
            ->where(fn ($consulta) => $consulta
                ->where(CatalogoVisible::para(auth()->id()))
                ->orWhereIn('id', $yaUsados)
            )
            ->get()
            ->sortBy(fn (Medicamento $m): string => mb_strtolower($m->nombre_comercial))
            ->values()
            ->map(fn (Medicamento $m): array => [
                'id' => $m->id,
                'nombre' => $m->nombre_comercial,
            ])
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function medicosDisponibles(Paciente $paciente): array
    {
        $yaUsados = $paciente->tratamientos()
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
            ->sortBy(fn (Medico $medico): string => mb_strtolower($medico->nombre))
            ->values()
            ->map(fn (Medico $medico): array => [
                'id' => $medico->id,
                'nombre' => $medico->nombre,
            ])
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
            ->map(fn (Enfermedad $enfermedad): array => [
                'id' => $enfermedad->id,
                'nombre' => $enfermedad->nombre,
            ])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(Tratamiento $tratamiento): array
    {
        return [
            'id' => $tratamiento->id,
            'medicamento_id' => $tratamiento->medicamento_id,
            'medicamentoNombre' => $tratamiento->medicamento->nombre_comercial,
            'medico_id' => $tratamiento->medico_id,
            'medicoNombre' => $tratamiento->medico?->nombre,
            'enfermedad_id' => $tratamiento->enfermedad_id,
            'enfermedadNombre' => $tratamiento->enfermedad?->nombre,
            'dosis' => $tratamiento->dosis,
            'frecuencia' => $tratamiento->frecuencia,
            'tomasPorDia' => $tratamiento->tomas_por_dia,
            'horas' => $tratamiento->horas ?? [],
            'tomasVisible' => $tratamiento->tomasVisible(),
            'horarioVisible' => $tratamiento->horarioVisible(),
            'periodicidad' => $tratamiento->periodicidad?->value,
            'diasSemana' => $tratamiento->dias_semana ?? [],
            'periodicidadEtiqueta' => $tratamiento->tomaVisible(),
            'fotos' => $tratamiento->adjuntos
                ->sortBy('id')
                ->values()
                ->map(fn (Adjunto $adjunto): array => $adjunto->paraLista())
                ->all(),
            'inicio' => $tratamiento->inicio->format('Y-m-d'),
            'inicioVisible' => $tratamiento->inicio->format('d/m/Y'),
            'fin' => $tratamiento->fin?->format('Y-m-d'),
            'finVisible' => $tratamiento->fin?->format('d/m/Y'),
            'activo' => $tratamiento->activo,
            'notas' => $tratamiento->notas,
        ];
    }
}
