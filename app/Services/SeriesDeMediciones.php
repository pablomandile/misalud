<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Medicion;
use App\Models\TipoMedicion;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Arma, a partir de un montón de mediciones, las series que dibuja
 * `GraficoEvolucion.vue`.
 *
 * Vive acá y no en un controlador porque tiene **dos consumidores**: la
 * pantalla de mediciones de un paciente y la ficha de una enfermedad, que
 * muestra la curva de lo que se está siguiendo por ella. Dos copias de este
 * cálculo serían dos lugares donde el promedio puede salir distinto.
 *
 * ⚠️ **Las cuentas van en PHP, nunca en SQL.** `valor` está cifrado: no
 * existe `AVG()`, ni `MIN()`, ni `ORDER BY valor` sobre esa columna —
 * devolverían basura sin dar error—. Se traen las filas (son decenas) y se
 * cuenta acá sobre los valores ya descifrados.
 */
class SeriesDeMediciones
{
    /**
     * Agrupadas por variable, con sus puntos y su resumen.
     *
     * Agrupadas y no en una lista cronológica única porque lo que se mira
     * es la evolución de cada cosa: un peso entre dos presiones no dice
     * nada. Y porque es lo que el gráfico necesita.
     *
     * @param  Collection<int, Medicion>  $mediciones
     * @return list<array<string, mixed>>
     */
    public function armar(Collection $mediciones, ?User $usuario): array
    {
        return array_values($mediciones
            ->groupBy('tipo_medicion_id')
            ->map(function (Collection $delTipo) use ($usuario): array {
                /** @var Medicion $primera */
                $primera = $delTipo->first();
                $tipo = $primera->tipo;

                return [
                    ...$this->descripcionDelTipo($tipo),
                    'resumen' => $this->resumen($delTipo, $tipo),
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
     * @return array<string, mixed>
     */
    public function serializar(Medicion $medicion, ?User $usuario): array
    {
        $tipo = $medicion->tipo;
        $enSuZona = $usuario?->enSuZona($medicion->fecha) ?? $medicion->fecha;

        return [
            'id' => $medicion->id,
            'tipo_medicion_id' => $medicion->tipo_medicion_id,
            'enfermedad_id' => $medicion->enfermedad_id,
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
             * para el gráfico, y `valorVisible` ya formateado con los
             * decimales que declara el tipo y con coma decimal. La columna
             * está cifrada, así que lo que sale del modelo es un STRING —ver
             * el comentario de `Medicion`—: mandarlo crudo invitaría a
             * ordenar o comparar texto del lado del cliente.
             */
            'valor' => $medicion->valorNumerico(),
            'valorVisible' => $tipo->formatear($medicion->valorNumerico()),
            'valorSecundario' => $medicion->valorSecundarioNumerico(),
            'valorSecundarioVisible' => $tipo->formatear($medicion->valorSecundarioNumerico()),

            'notas' => $medicion->notas,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function descripcionDelTipo(TipoMedicion $tipo): array
    {
        return [
            'tipoId' => $tipo->id,
            'nombre' => $tipo->nombre,
            'unidad' => $tipo->unidad,
            'unidadSecundaria' => $tipo->unidadSecundariaVisible(),
            'etiquetaPrincipal' => $tipo->etiquetaPrincipalVisible(),
            'etiquetaSecundaria' => $tipo->etiqueta_secundaria,
            'tieneValorSecundario' => $tipo->tieneValorSecundario(),
            'decimales' => $tipo->decimales,
            /*
             * Rango de REFERENCIA, no un veredicto: la pantalla lo muestra
             * al lado del valor como lo hace un análisis de laboratorio.
             * Ninguna vista pinta un número de rojo ni dice si está mal
             * (regla 1: el sistema registra, no aconseja).
             */
            'minNormal' => $tipo->min_normal,
            'maxNormal' => $tipo->max_normal,
            'minNormalSecundario' => $tipo->min_normal_secundario,
            'maxNormalSecundario' => $tipo->max_normal_secundario,
        ];
    }

    /**
     * Mínimo, máximo y promedio, redondeados a los decimales del tipo para
     * que no aparezca un promedio de peso con catorce cifras.
     *
     * @param  Collection<int, Medicion>  $delTipo
     * @return array<string, mixed>
     */
    private function resumen(Collection $delTipo, TipoMedicion $tipo): array
    {
        $valores = $delTipo->map(fn (Medicion $m): float => $m->valorNumerico());
        $secundarios = $delTipo
            ->map(fn (Medicion $m): ?float => $m->valorSecundarioNumerico())
            ->filter(fn (?float $v): bool => $v !== null);

        return [
            'cantidad' => $delTipo->count(),
            'minimo' => $tipo->formatear($valores->min()),
            'maximo' => $tipo->formatear($valores->max()),
            'promedio' => $tipo->formatear($valores->count() > 0 ? $valores->avg() : null),
            'minimoSecundario' => $tipo->formatear($secundarios->min()),
            'maximoSecundario' => $tipo->formatear($secundarios->max()),
            'promedioSecundario' => $tipo->formatear(
                $secundarios->count() > 0 ? $secundarios->avg() : null,
            ),
        ];
    }
}
