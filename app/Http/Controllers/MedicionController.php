<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MedicionGuardarRequest;
use App\Models\Enfermedad;
use App\Models\Medicion;
use App\Models\Paciente;
use App\Models\TipoMedicion;
use App\Services\SeriesDeMediciones;
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
    public function __construct(private readonly SeriesDeMediciones $series) {}

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
            'enfermedades' => $this->enfermedadesDeLaFicha($paciente),
            'series' => $this->series->armar($mediciones, $usuario),
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
            ...$peticion->safe([
                'tipo_medicion_id',
                'enfermedad_id',
                'valor',
                'valor_secundario',
                'notas',
            ]),
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
     * Las enfermedades de esta ficha, para poder vincular una medición.
     *
     * Solo del mismo paciente: es lo mismo que valida el FormRequest.
     * Vincular una medición a la enfermedad de otra persona haría que la
     * curva de esa enfermedad mostrara valores ajenos.
     *
     * @return list<array<string, mixed>>
     */
    private function enfermedadesDeLaFicha(Paciente $paciente): array
    {
        return array_values($paciente->enfermedades()
            ->get()
            // En PHP: `nombre` está cifrado.
            ->sortBy(fn (Enfermedad $enfermedad): string => mb_strtolower($enfermedad->nombre))
            ->values()
            ->map(fn (Enfermedad $enfermedad): array => [
                'id' => $enfermedad->id,
                'nombre' => $enfermedad->nombre,
            ])
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
}
