<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EnfermedadGuardarRequest;
use App\Models\Alergia;
use App\Models\Enfermedad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\RegistroEnfermedad;
use App\Services\SeriesDeMediciones;
use App\Support\CatalogoVisible;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Enfermedades, su bitácora y las alergias de un paciente.
 *
 * Las tres cosas en **una pantalla** y no en tres: son pocas filas cada
 * una, se consultan juntas, y a una ficha de paciente ya se le colgaban
 * cuatro botones. Las alergias van primero porque son lo que alguien busca
 * apurado.
 *
 * Las alergias no tienen `index` propio —viajan como prop de esta
 * pantalla—, el mismo patrón que ya usan las coberturas.
 */
class EnfermedadController extends Controller
{
    public function __construct(private readonly SeriesDeMediciones $series) {}

    public function index(Paciente $paciente): Response
    {
        Gate::authorize('view', $paciente);

        $usuario = auth()->user();
        $puedeEditar = $paciente->rolDe($usuario)?->puedeEditar() ?? false;

        $enfermedades = $paciente->enfermedades()
            // Explícito: sin esto es una consulta por cada fila, y tres por
            // cada enfermedad con bitácora y mediciones.
            ->with(['medico', 'registros', 'mediciones.tipo'])
            ->get()
            /*
             * Primero lo vigente y, dentro de cada grupo, lo más reciente.
             * `nombre` está cifrado: ordenar por texto sería en PHP igual,
             * pero acá lo que importa es la fecha, que está en claro.
             */
            ->sortBy(fn (Enfermedad $e): string => ($e->estado->estaVigente() ? '0' : '1')
                .str_pad((string) (99999999 - (int) ($e->fecha_diagnostico?->format('Ymd') ?? 0)), 8, '0', STR_PAD_LEFT))
            ->values()
            ->map(fn (Enfermedad $enfermedad): array => $this->serializar($enfermedad))
            ->all();

        return Inertia::render('enfermedades/Index', [
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
                'puedeEditar' => $puedeEditar,
            ],
            'enfermedades' => $enfermedades,
            'alergias' => $this->alergias($paciente),
            'medicos' => $this->medicosDisponibles($paciente),
            'hoy' => $usuario?->hoyCalendario()->format('Y-m-d'),
        ]);
    }

    public function store(EnfermedadGuardarRequest $peticion, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('crearEn', [Enfermedad::class, $paciente]);

        $enfermedad = $paciente->enfermedades()->create($peticion->validated());

        return back()->with('exito', "Se agregó {$enfermedad->nombre}.");
    }

    public function update(EnfermedadGuardarRequest $peticion, Enfermedad $enfermedad): RedirectResponse
    {
        Gate::authorize('update', $enfermedad);

        $enfermedad->update($peticion->validated());

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(Enfermedad $enfermedad): RedirectResponse
    {
        Gate::authorize('delete', $enfermedad);

        $nombre = $enfermedad->nombre;
        $enfermedad->delete();

        return back()->with('exito', "Se eliminó {$nombre}.");
    }

    /**
     * Los médicos que se pueden elegir: los de esta persona -propios y
     * semillas- más los que esta ficha ya viene usando.
     *
     * Mismo criterio que valida `EnfermedadGuardarRequest`. Si el
     * formulario ofreciera algo que la validación rechaza, el error
     * aparecería recién al guardar.
     *
     * @return list<array<string, mixed>>
     */
    private function medicosDisponibles(Paciente $paciente): array
    {
        $yaUsados = $paciente->enfermedades()
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
     * @return list<array<string, mixed>>
     */
    private function alergias(Paciente $paciente): array
    {
        return array_values($paciente->alergias()
            ->get()
            ->sortBy(fn (Alergia $alergia): string => mb_strtolower($alergia->sustancia))
            ->values()
            ->map(fn (Alergia $alergia): array => [
                'id' => $alergia->id,
                'sustancia' => $alergia->sustancia,
                'reaccion' => $alergia->reaccion,
                'severidad' => $alergia->severidad->value,
                'severidadEtiqueta' => $alergia->severidad->etiqueta(),
                'notas' => $alergia->notas,
            ])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(Enfermedad $enfermedad): array
    {
        return [
            'id' => $enfermedad->id,
            'nombre' => $enfermedad->nombre,
            'fecha_diagnostico' => $enfermedad->fecha_diagnostico?->format('Y-m-d'),
            'fechaVisible' => $enfermedad->fecha_diagnostico?->format('d/m/Y'),
            'estado' => $enfermedad->estado->value,
            'estadoEtiqueta' => $enfermedad->estado->etiqueta(),
            'estaVigente' => $enfermedad->estado->estaVigente(),
            'medico_id' => $enfermedad->medico_id,
            'medicoNombre' => $enfermedad->medico?->nombre,
            'notas' => $enfermedad->notas,

            'registros' => $enfermedad->registros
                ->sortByDesc('fecha')
                ->values()
                ->map(fn (RegistroEnfermedad $registro): array => [
                    'id' => $registro->id,
                    'fecha' => $registro->fecha->format('Y-m-d'),
                    'fechaVisible' => $registro->fecha->format('d/m/Y'),
                    'nota' => $registro->nota,
                ])
                ->all(),

            /*
             * La curva de lo que se está siguiendo por esta enfermedad.
             *
             * Los números NO viven en la bitácora -ver la migración de
             * `registros_enfermedad`-: viven en `mediciones` y apuntan acá.
             * El mismo servicio que arma la pantalla de mediciones arma
             * esto, así que el promedio de una presión es el mismo número
             * se lo mire desde donde se lo mire.
             */
            'series' => $this->series->armar($enfermedad->mediciones, auth()->user()),
        ];
    }
}
