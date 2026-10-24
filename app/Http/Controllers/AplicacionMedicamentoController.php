<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PeriodicidadToma;
use App\Http\Requests\AplicacionMedicamentoGuardarRequest;
use App\Models\AplicacionMedicamento;
use App\Models\Enfermedad;
use App\Models\Paciente;
use App\Models\Tratamiento;
use App\Models\User;
use App\Services\PacientePrincipal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La sección «Tratamiento»: cada aplicación o toma de un medicamento que no es
 * diario, agrupada por la enfermedad por la que se hizo. La pregunta que
 * contesta es "¿cuándo me di la última de esta, y por qué?".
 */
class AplicacionMedicamentoController extends Controller
{
    public function index(Paciente $paciente): Response
    {
        Gate::authorize('view', $paciente);

        $usuario = auth()->user();

        $aplicaciones = $paciente->aplicacionesMedicamento()
            // Explícito: sin esto es una consulta por cada fila.
            ->with(['tratamiento.medicamento', 'enfermedad'])
            ->get()
            // Un medicamento borrado se lleva su historial de la pantalla.
            ->filter(fn (AplicacionMedicamento $a): bool => ! $a->tratamiento->trashed())
            ->sortByDesc(fn (AplicacionMedicamento $a): string => $a->fecha->format('Ymd').str_pad((string) $a->id, 10, '0', STR_PAD_LEFT))
            ->values();

        /*
         * Un grupo por enfermedad, la que tiene la aplicación más reciente
         * arriba; las que no tienen enfermedad, al final. En PHP: el nombre
         * está cifrado.
         */
        $grupos = $aplicaciones
            ->groupBy(fn (AplicacionMedicamento $a): int => $a->enfermedad_id ?? 0)
            ->map(function (Collection $filas, int $enfermedadId): array {
                /** @var AplicacionMedicamento $ultima */
                $ultima = $filas->first();

                return [
                    'enfermedadId' => $enfermedadId === 0 ? null : $enfermedadId,
                    'nombre' => $ultima->enfermedad === null ? 'Sin enfermedad' : $ultima->enfermedad->nombre,
                    'orden' => ($enfermedadId === 0 ? '0' : '1').$ultima->fecha->format('Ymd'),
                    'aplicaciones' => $filas->map(fn (AplicacionMedicamento $a): array => $this->serializar($a))->values()->all(),
                ];
            })
            ->sortByDesc('orden')
            ->values()
            ->all();

        return Inertia::render('aplicaciones-medicamento/Index', [
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
                'puedeEditar' => $paciente->rolDe($usuario)?->puedeEditar() ?? false,
            ],
            'fichas' => $usuario instanceof User ? PacientePrincipal::opciones($usuario) : [],
            'grupos' => $grupos,
            'medicamentos' => $this->medicamentosNoDiarios($paciente),
            'enfermedades' => $paciente->enfermedades()
                ->get()
                ->map(fn (Enfermedad $e): array => ['id' => $e->id, 'nombre' => $e->nombre])
                ->sortBy(fn (array $e): string => mb_strtolower($e['nombre']))
                ->values()
                ->all(),
            'hoy' => $usuario?->hoyCalendario()->format('Y-m-d'),
        ]);
    }

    public function store(AplicacionMedicamentoGuardarRequest $peticion, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('crearEn', [AplicacionMedicamento::class, $paciente]);

        $paciente->aplicacionesMedicamento()->create($peticion->validated());

        return back()->with('exito', 'Se anotó la aplicación.');
    }

    public function update(AplicacionMedicamentoGuardarRequest $peticion, AplicacionMedicamento $aplicacionMedicamento): RedirectResponse
    {
        Gate::authorize('update', $aplicacionMedicamento);

        $aplicacionMedicamento->update($peticion->validated());

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(AplicacionMedicamento $aplicacionMedicamento): RedirectResponse
    {
        Gate::authorize('delete', $aplicacionMedicamento);

        $aplicacionMedicamento->delete();

        return back()->with('exito', 'Se eliminó la aplicación.');
    }

    /**
     * Los medicamentos de la ficha que se pueden anotar: todos menos los de
     * "todos los días", con la enfermedad que el formulario precarga. Mismo
     * criterio que valida `AplicacionMedicamentoGuardarRequest`.
     *
     * @return list<array{id: int, nombre: string, enfermedadId: int|null}>
     */
    private function medicamentosNoDiarios(Paciente $paciente): array
    {
        return array_values($paciente->tratamientos()
            ->with('medicamento')
            ->where(fn ($toma) => $toma
                ->whereNull('periodicidad')
                ->orWhere('periodicidad', '!=', PeriodicidadToma::Diaria->value))
            ->get()
            ->map(fn (Tratamiento $t): array => [
                'id' => $t->id,
                'nombre' => $t->medicamento->nombre_comercial
                    .($t->tomaVisible() !== null ? ' · '.$t->tomaVisible() : '')
                    .($t->activo ? '' : ' (suspendido)'),
                'enfermedadId' => $t->enfermedad_id,
            ])
            ->sortBy(fn (array $m): string => mb_strtolower($m['nombre']))
            ->values()
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(AplicacionMedicamento $aplicacion): array
    {
        return [
            'id' => $aplicacion->id,
            'tratamiento_id' => $aplicacion->tratamiento_id,
            'medicamentoNombre' => $aplicacion->tratamiento->medicamento->nombre_comercial,
            'dosis' => $aplicacion->tratamiento->dosis,
            'enfermedad_id' => $aplicacion->enfermedad_id,
            'fecha' => $aplicacion->fecha->format('Y-m-d'),
            'fechaVisible' => $aplicacion->fecha->format('d/m/Y'),
            'notas' => $aplicacion->notas,
        ];
    }
}
