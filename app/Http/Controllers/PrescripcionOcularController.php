<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Ojo;
use App\Enums\TipoPrescripcionOcular;
use App\Http\Requests\PrescripcionOcularGuardarRequest;
use App\Models\Adjunto;
use App\Models\Centro;
use App\Models\GraduacionOcular;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\PrescripcionOcular;
use App\Support\CatalogoVisible;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las recetas de anteojos de un paciente.
 *
 * Cada receta trae **siempre sus dos ojos**, aunque uno no necesite
 * corrección: es lo que hace que la pantalla se pueda comparar contra el
 * papel línea por línea, que es la única verificación real que existe acá.
 */
class PrescripcionOcularController extends Controller
{
    public function index(Paciente $paciente): Response
    {
        Gate::authorize('view', $paciente);

        $usuario = auth()->user();

        $prescripciones = $paciente->prescripcionesOculares()
            // Explícito: sin esto son dos consultas por cada receta del listado.
            ->with(['medico', 'centro', 'graduaciones', 'adjuntos'])
            ->get();

        return Inertia::render('ocular/Index', [
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
                'puedeEditar' => $paciente->rolDe($usuario)?->puedeEditar() ?? false,
            ],
            'prescripciones' => $prescripciones
                ->sortByDesc(fn (PrescripcionOcular $receta): string => $receta->fecha->format('Ymd'))
                ->values()
                ->map(fn (PrescripcionOcular $receta): array => $this->serializar($receta))
                ->all(),
            'tipos' => array_map(
                fn (TipoPrescripcionOcular $tipo): array => [
                    'valor' => $tipo->value,
                    'etiqueta' => $tipo->etiqueta(),
                ],
                TipoPrescripcionOcular::cases(),
            ),
            'medicos' => $this->medicosDisponibles($paciente),
            'centros' => $this->centrosDisponibles($paciente),
            'evolucion' => $this->evolucion($prescripciones),
            'hoy' => $usuario?->hoyCalendario()->format('Y-m-d'),
        ]);
    }

    /**
     * ⚠️ **En una transacción, y no por prolijidad.**
     *
     * El invariante de toda esta etapa es "una receta tiene dos ojos". Si la
     * cabecera se guardara y la segunda graduación fallara, quedaría una
     * receta a la que le falta un ojo —y no habría forma de saber si ese ojo
     * está sano o si nunca se cargó, que es justamente la ambigüedad que el
     * esquema evita—.
     */
    public function store(PrescripcionOcularGuardarRequest $peticion, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('crearEn', [PrescripcionOcular::class, $paciente]);

        DB::transaction(function () use ($peticion, $paciente): void {
            $prescripcion = $paciente->prescripcionesOculares()->create(
                $peticion->safe(['medico_id', 'centro_id', 'tipo', 'fecha', 'dp_total', 'notas']),
            );

            foreach (Ojo::cases() as $ojo) {
                // Por la relación: `prescripcion_id` no es fillable, es la FK
                // de la que cuelga la autorización.
                $prescripcion->graduaciones()->create($peticion->datosDelOjo($ojo));
            }
        });

        return back()->with('exito', 'Se agregó la receta.');
    }

    public function update(
        PrescripcionOcularGuardarRequest $peticion,
        PrescripcionOcular $prescripcion,
    ): RedirectResponse {
        Gate::authorize('update', $prescripcion);

        DB::transaction(function () use ($peticion, $prescripcion): void {
            $prescripcion->update(
                $peticion->safe(['medico_id', 'centro_id', 'tipo', 'fecha', 'dp_total', 'notas']),
            );

            foreach (Ojo::cases() as $ojo) {
                /*
                 * `updateOrCreate` y no `update`: una receta siempre debería
                 * tener sus dos filas, pero si alguna faltara —una fila
                 * borrada a mano en la base, una restaurada a medias— editar
                 * la receta es el momento de completarla, no de fallar.
                 */
                $prescripcion->graduaciones()->updateOrCreate(
                    ['ojo' => $ojo],
                    $peticion->datosDelOjo($ojo),
                );
            }
        });

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(PrescripcionOcular $prescripcion): RedirectResponse
    {
        Gate::authorize('delete', $prescripcion);

        $prescripcion->delete();

        return back()->with('exito', 'Se eliminó la receta.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function medicosDisponibles(Paciente $paciente): array
    {
        $yaUsados = $paciente->prescripcionesOculares()
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
            ->map(fn (Medico $medico): array => ['id' => $medico->id, 'nombre' => $medico->nombre])
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function centrosDisponibles(Paciente $paciente): array
    {
        $yaUsados = $paciente->prescripcionesOculares()
            ->whereNotNull('centro_id')
            ->pluck('centro_id')
            ->unique()
            ->all();

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
     * La evolución de la esfera de cada ojo, para quien tenga varias
     * recetas a lo largo del tiempo. Reusa `GraficoEvolucion.vue` (paso 9.4).
     *
     * **Dos series independientes, no una con OD de principal y OI de
     * secundario** -como hace una presión con sistólica/diastólica-: acá un
     * ojo puede no tener corrección mientras el otro sí (`numero()` da
     * `null`), y `GraficoEvolucion` no admite un punto con `valor` nulo.
     * Cada serie se arma y se filtra por su cuenta, mismo criterio que la
     * evolución de un resultado de estudio.
     *
     * @param  Collection<int, PrescripcionOcular>  $prescripciones  con sus graduaciones ya cargadas
     * @return list<array<string, mixed>>
     */
    private function evolucion(Collection $prescripciones): array
    {
        $ordenadas = $prescripciones->sortBy(
            fn (PrescripcionOcular $r): string => $r->fecha->format('Ymd'),
        );

        $series = [];

        foreach (Ojo::cases() as $ojo) {
            $puntos = $ordenadas
                ->map(function (PrescripcionOcular $r) use ($ojo): ?array {
                    $numero = $r->graduacionDe($ojo)?->numero('esfera');

                    if ($numero === null) {
                        return null;
                    }

                    return [
                        'fechaIso' => $r->fecha->toIso8601String(),
                        'fechaVisible' => $r->fecha->format('d/m/Y'),
                        'valor' => $numero,
                        'valorSecundario' => null,
                    ];
                })
                ->filter()
                ->values();

            // Con menos de dos puntos no hay evolución que graficar: una
            // línea de un punto no es una evolución (misma regla que
            // mediciones y resultados de estudios).
            if ($puntos->count() < 2) {
                continue;
            }

            $valores = $puntos->pluck('valor');

            $series[] = [
                'ojo' => $ojo->value,
                'etiqueta' => $ojo->etiqueta(),
                'puntos' => $puntos->all(),
                'resumen' => [
                    'cantidad' => $puntos->count(),
                    'minimo' => GraduacionOcular::formatearDioptria((float) $valores->min()),
                    'maximo' => GraduacionOcular::formatearDioptria((float) $valores->max()),
                    'promedio' => GraduacionOcular::formatearDioptria((float) $valores->avg()),
                ],
            ];
        }

        return $series;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(PrescripcionOcular $prescripcion): array
    {
        return [
            'id' => $prescripcion->id,
            'tipo' => $prescripcion->tipo->value,
            'tipoEtiqueta' => $prescripcion->tipo->etiqueta(),
            'fecha' => $prescripcion->fecha->format('Y-m-d'),
            'fechaVisible' => $prescripcion->fecha->format('d/m/Y'),
            'medico_id' => $prescripcion->medico_id,
            'medicoNombre' => $prescripcion->medico?->nombre,
            'centro_id' => $prescripcion->centro_id,
            'centroNombre' => $prescripcion->centro?->nombre,
            'dp_total' => $prescripcion->dp_total,
            'notas' => $prescripcion->notas,

            /*
             * Los dos ojos, siempre los dos, y **en el orden en que se
             * miran**: `od` primero. Ese orden lo respeta la pantalla porque
             * mirando a la persona, su ojo derecho queda a la izquierda.
             */
            'ojos' => [
                'od' => $this->serializarOjo($prescripcion->graduacionDe(Ojo::Derecho)),
                'oi' => $this->serializarOjo($prescripcion->graduacionDe(Ojo::Izquierdo)),
            ],

            'adjuntos' => $prescripcion->adjuntos
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

    /**
     * Un ojo en dos formas: la cruda, que es la que vuelve al formulario, y
     * la visible, ya formateada con signo y dos decimales.
     *
     * Es el mismo reparto que usan las mediciones (`valor` y `valorVisible`)
     * y por el mismo motivo: el formulario necesita el texto tal cual se
     * guardó, y la pantalla necesita el que se lee como una receta.
     *
     * @return array<string, mixed>
     */
    private function serializarOjo(?GraduacionOcular $graduacion): array
    {
        return [
            'esfera' => $graduacion?->esfera,
            'esferaVisible' => $graduacion?->dioptriaVisible('esfera'),
            'cilindro' => $graduacion?->cilindro,
            'cilindroVisible' => $graduacion?->dioptriaVisible('cilindro'),
            'eje' => $graduacion?->eje,
            'adicion' => $graduacion?->adicion,
            'adicionVisible' => $graduacion?->dioptriaVisible('adicion'),
            'dp_monocular' => $graduacion?->dp_monocular,
            'prisma' => $graduacion?->prisma,
            'base' => $graduacion?->base,
            'agudeza_visual' => $graduacion?->agudeza_visual,

            'resumen' => $graduacion?->resumen(),
            // Regla 2: sin datos se dice "sin datos", no se infiere nada.
            'sinDatos' => ! ($graduacion?->tieneDatos() ?? false),
        ];
    }
}
