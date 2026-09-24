<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MedicionGuardarRequest;
use App\Models\Medicion;
use App\Models\Paciente;
use App\Models\TipoMedicion;
use App\Models\User;
use App\Support\CatalogoVisible;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El seguimiento de variables de un paciente: peso, presión, glucemia.
 *
 * Va por paciente y no por "paciente activo" —como el dashboard— porque una
 * medición pertenece a una persona concreta y confundirse de ficha acá es
 * cargarle el peso de un familiar a otro. La ruta lo dice: la elección no
 * queda escondida en la sesión.
 */
class MedicionController extends Controller
{
    /**
     * Las mediciones de un paciente, de la más nueva a la más vieja.
     *
     * `fecha` está en claro, así que **este `orderBy` sí puede vivir en
     * SQL** —a diferencia de cualquier orden por valor, que es imposible
     * sobre una columna cifrada—.
     */
    public function index(Paciente $paciente): Response
    {
        Gate::authorize('view', $paciente);

        $usuario = auth()->user();

        $mediciones = $paciente->mediciones()
            // Explícito: sin esto es una consulta por cada fila del listado.
            ->with('tipo')
            ->orderByDesc('fecha')
            ->get();

        return Inertia::render('mediciones/Index', [
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
                'puedeEditar' => $paciente->rolDe($usuario)?->puedeEditar() ?? false,
            ],
            'tipos' => $this->tiposDisponibles($paciente),
            'series' => $this->series($mediciones, $usuario),
            'imc' => $this->imc($mediciones),

            /*
             * El "ahora" con el que el formulario se precarga sale del
             * SERVIDOR, en la zona de la cuenta, y no de `new Date()` en el
             * navegador. Si la persona está de viaje —o el celular tiene
             * otra zona que la cuenta—, precargar con el reloj del navegador
             * escribiría una hora que el servidor después reinterpreta en la
             * zona de la cuenta, y la medición quedaría corrida sin que nada
             * lo avise. Así, lo que se precarga y lo que se interpreta son
             * el mismo reloj.
             */
            'ahoraLocal' => $usuario?->ahora()->format('Y-m-d\TH:i'),
            'zonaHoraria' => $usuario?->zona_horaria,
        ]);
    }

    public function store(MedicionGuardarRequest $peticion, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('crearEn', [Medicion::class, $paciente]);

        $paciente->mediciones()->create($this->datos($peticion));

        return back()->with('exito', 'Se guardó la medición.');
    }

    public function update(MedicionGuardarRequest $peticion, Medicion $medicion): RedirectResponse
    {
        Gate::authorize('update', $medicion);

        $medicion->update($this->datos($peticion));

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(Medicion $medicion): RedirectResponse
    {
        Gate::authorize('delete', $medicion);

        $medicion->delete();

        return back()->with('exito', 'Se eliminó la medición.');
    }

    /**
     * Lo que se guarda, con la fecha ya pasada a UTC.
     *
     * La conversión la hace el FormRequest (`fechaEnUtc()`), que es donde
     * vive la zona de quien carga: acá solo se usa. Escribir la fecha tal
     * como llegó del formulario correría el registro tantas horas como diga
     * el huso, sin ningún síntoma.
     *
     * @return array<string, mixed>
     */
    private function datos(MedicionGuardarRequest $peticion): array
    {
        return [
            ...$peticion->safe(['tipo_medicion_id', 'valor', 'valor_secundario', 'notas']),
            'fecha' => $peticion->fechaEnUtc(),
        ];
    }

    /**
     * Las variables que se pueden elegir en esta ficha: las de esta persona
     * -propias y semillas- más las que la ficha **ya viene usando**.
     *
     * La segunda mitad es la que permite que un cuidador siga la serie que
     * arrancó el dueño en vez de crear un "Peso" paralelo. Tiene que ser el
     * MISMO criterio que valida `MedicionGuardarRequest`: si el formulario
     * ofreciera algo que la validación rechaza -o al revés-, el error
     * aparecería recién al guardar.
     *
     * Se ordena en PHP porque `nombre` está cifrado.
     *
     * @return list<array<string, mixed>>
     */
    private function tiposDisponibles(Paciente $paciente): array
    {
        $yaUsados = $paciente->mediciones()->pluck('tipo_medicion_id')->unique()->all();

        return array_values(TipoMedicion::query()
            ->where(fn ($consulta) => $consulta
                ->where(CatalogoVisible::para(auth()->id()))
                ->orWhereIn('id', $yaUsados)
            )
            ->get()
            ->sortBy(fn (TipoMedicion $tipo): string => mb_strtolower($tipo->nombre))
            ->values()
            ->map(fn (TipoMedicion $tipo): array => [
                'id' => $tipo->id,
                'nombre' => $tipo->nombre,
                'unidad' => $tipo->unidad,
                'unidadSecundaria' => $tipo->unidadSecundariaVisible(),
                'etiquetaPrincipal' => $tipo->etiquetaPrincipalVisible(),
                'etiquetaSecundaria' => $tipo->etiqueta_secundaria,
                'tieneValorSecundario' => $tipo->tieneValorSecundario(),
                'decimales' => $tipo->decimales,
                /*
                 * Rango de REFERENCIA, no un veredicto: la pantalla lo
                 * muestra al lado del valor como lo hace un análisis de
                 * laboratorio. Ninguna vista pinta un número de rojo ni dice
                 * si está mal (regla 1: el sistema registra, no aconseja).
                 */
                'minNormal' => $tipo->min_normal,
                'maxNormal' => $tipo->max_normal,
                'minNormalSecundario' => $tipo->min_normal_secundario,
                'maxNormalSecundario' => $tipo->max_normal_secundario,
            ])
            ->all());
    }

    /**
     * Las mediciones agrupadas POR VARIABLE, cada una con sus puntos para el
     * gráfico y su resumen.
     *
     * Agrupadas y no en una lista cronológica única porque lo que se mira
     * acá es la evolución de cada cosa: un peso entre dos presiones no dice
     * nada. Y porque es lo que el gráfico necesita.
     *
     * ⚠️ **Las cuentas van en PHP, nunca en SQL.** `valor` está cifrado: no
     * existe `AVG()`, ni `MIN()`, ni `ORDER BY valor` sobre esa columna —
     * devolverían basura sin dar error—. Se traen las filas (son decenas) y
     * se cuenta acá sobre los valores ya descifrados.
     *
     * @param  Collection<int, Medicion>  $mediciones
     * @return list<array<string, mixed>>
     */
    private function series(Collection $mediciones, ?User $usuario): array
    {
        return array_values($mediciones
            ->groupBy('tipo_medicion_id')
            ->map(function (Collection $delTipo) use ($usuario): array {
                /** @var Medicion $primera */
                $primera = $delTipo->first();
                $tipo = $primera->tipo;

                $valores = $delTipo->map(fn (Medicion $m): float => $m->valorNumerico());
                $secundarios = $delTipo
                    ->map(fn (Medicion $m): ?float => $m->valorSecundarioNumerico())
                    ->filter(fn (?float $v): bool => $v !== null);

                return [
                    'tipoId' => $tipo->id,
                    'nombre' => $tipo->nombre,
                    'unidad' => $tipo->unidad,
                    'unidadSecundaria' => $tipo->unidadSecundariaVisible(),
                    'etiquetaPrincipal' => $tipo->etiquetaPrincipalVisible(),
                    'etiquetaSecundaria' => $tipo->etiqueta_secundaria,
                    'tieneValorSecundario' => $tipo->tieneValorSecundario(),
                    'decimales' => $tipo->decimales,
                    'minNormal' => $tipo->min_normal,
                    'maxNormal' => $tipo->max_normal,
                    'minNormalSecundario' => $tipo->min_normal_secundario,
                    'maxNormalSecundario' => $tipo->max_normal_secundario,

                    /*
                     * El resumen: mínimo, máximo y promedio, calculados en la
                     * colección. Redondeados a los decimales del tipo para que
                     * no aparezca un promedio de peso con catorce cifras.
                     */
                    'resumen' => [
                        'cantidad' => $delTipo->count(),
                        'minimo' => $tipo->formatear($valores->min()),
                        'maximo' => $tipo->formatear($valores->max()),
                        'promedio' => $tipo->formatear(
                            $valores->count() > 0 ? $valores->avg() : null,
                        ),
                        'minimoSecundario' => $tipo->formatear($secundarios->min()),
                        'maximoSecundario' => $tipo->formatear($secundarios->max()),
                        'promedioSecundario' => $tipo->formatear(
                            $secundarios->count() > 0 ? $secundarios->avg() : null,
                        ),
                    ],

                    // De la más nueva a la más vieja, igual que llegan.
                    'mediciones' => $delTipo
                        ->map(fn (Medicion $m): array => $this->serializar($m, $usuario))
                        ->values()
                        ->all(),
                ];
            })
            // Arriba la variable con la medición más reciente: es la que la
            // persona viene siguiendo.
            ->sortByDesc(fn (array $serie): string => $serie['mediciones'][0]['fechaIso'])
            ->values()
            ->all());
    }

    /**
     * El IMC, derivado del último peso y la última altura.
     *
     * **No se guarda** (regla 4 de CLAUDE.md): guardarlo lo dejaría viejo al
     * día siguiente de pesarse. Se calcula en cada request a partir de las
     * dos mediciones más recientes.
     *
     * Reconoce las variables por `clave` y no por el nombre, que está
     * cifrado y además lo puede editar la persona (ver la migración de
     * `clave`).
     *
     * Devuelve el número y de dónde salió, **sin ninguna categoría**: decir
     * "sobrepeso" sería interpretar, y eso es del médico (regla 1).
     *
     * @param  Collection<int, Medicion>  $mediciones
     * @return array<string, mixed>|null
     */
    private function imc(Collection $mediciones): ?array
    {
        $ultima = fn (string $clave): ?Medicion => $mediciones
            ->first(fn (Medicion $m): bool => $m->tipo->clave === $clave);

        $peso = $ultima(TipoMedicion::CLAVE_PESO);
        $altura = $ultima(TipoMedicion::CLAVE_ALTURA);

        if ($peso === null || $altura === null) {
            return null;
        }

        $metros = $altura->valorNumerico() / 100;

        // Una altura en cero o negativa no es un dato: es una carga mal
        // hecha. Dividir por ella sería un error de PHP en pantalla.
        if ($metros <= 0) {
            return null;
        }

        return [
            'valor' => number_format($peso->valorNumerico() / ($metros ** 2), 1, ',', '.'),
            'pesoUsado' => $peso->tipo->formatear($peso->valorNumerico()).' '.$peso->tipo->unidad,
            'alturaUsada' => $altura->tipo->formatear($altura->valorNumerico()).' '.$altura->tipo->unidad,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(Medicion $medicion, ?User $usuario): array
    {
        $tipo = $medicion->tipo;
        $enSuZona = $usuario?->enSuZona($medicion->fecha) ?? $medicion->fecha;

        return [
            'id' => $medicion->id,
            'tipo_medicion_id' => $medicion->tipo_medicion_id,
            'tipoNombre' => $tipo->nombre,
            'unidad' => $tipo->unidad,
            'unidadSecundaria' => $tipo->unidadSecundariaVisible(),
            'etiquetaPrincipal' => $tipo->etiquetaPrincipalVisible(),
            'etiquetaSecundaria' => $tipo->etiqueta_secundaria,

            /*
             * Tres formas de la misma fecha, cada una para algo distinto:
             * la legible para mostrar, la del `datetime-local` para editar,
             * y el ISO para ordenar o agrupar en el cliente. Las tres salen
             * de la zona de quien mira, no de UTC.
             */
            'fechaVisible' => $enSuZona->format('d/m/Y H:i'),
            'fechaLocal' => $enSuZona->format('Y-m-d\TH:i'),
            'fechaIso' => $enSuZona->toIso8601String(),

            /*
             * El número va en las dos formas a propósito: `valor` como float
             * para el gráfico del paso 6.3, y `valorVisible` ya formateado
             * con los decimales que declara el tipo y con coma decimal. La
             * columna está cifrada, así que lo que sale del modelo es un
             * STRING —ver el comentario de `Medicion`—: mandarlo crudo
             * invitaría a ordenar o comparar texto del lado del cliente.
             */
            'valor' => $medicion->valorNumerico(),
            'valorVisible' => $tipo->formatear($medicion->valorNumerico()),
            'valorSecundario' => $medicion->valorSecundarioNumerico(),
            'valorSecundarioVisible' => $tipo->formatear($medicion->valorSecundarioNumerico()),

            'notas' => $medicion->notas,
        ];
    }
}
