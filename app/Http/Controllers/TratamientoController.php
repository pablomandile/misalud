<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TratamientoGuardarRequest;
use App\Models\Enfermedad;
use App\Models\Medicamento;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Tratamiento;
use App\Support\CatalogoVisible;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los tratamientos de un paciente: qué medicamento toma, con qué dosis.
 */
class TratamientoController extends Controller
{
    public function index(Paciente $paciente): Response
    {
        Gate::authorize('view', $paciente);

        $usuario = auth()->user();

        $tratamientos = $paciente->tratamientos()
            // Explícito: sin esto es una consulta por cada fila del listado.
            ->with(['medicamento', 'medico', 'enfermedad'])
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

        $paciente->tratamientos()->create([
            ...$peticion->validated(),
            'activo' => $peticion->boolean('activo', true),
        ]);

        return back()->with('exito', 'Se agregó el tratamiento.');
    }

    public function update(TratamientoGuardarRequest $peticion, Tratamiento $tratamiento): RedirectResponse
    {
        Gate::authorize('update', $tratamiento);

        $tratamiento->update([
            ...$peticion->validated(),
            'activo' => $peticion->boolean('activo'),
        ]);

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(Tratamiento $tratamiento): RedirectResponse
    {
        Gate::authorize('delete', $tratamiento);

        $tratamiento->delete();

        return back()->with('exito', 'Se eliminó el tratamiento.');
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
            'inicio' => $tratamiento->inicio->format('Y-m-d'),
            'inicioVisible' => $tratamiento->inicio->format('d/m/Y'),
            'fin' => $tratamiento->fin?->format('Y-m-d'),
            'finVisible' => $tratamiento->fin?->format('d/m/Y'),
            'activo' => $tratamiento->activo,
            'notas' => $tratamiento->notas,
        ];
    }
}
