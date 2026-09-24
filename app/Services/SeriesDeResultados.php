<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ResultadoEstudio;
use Illuminate\Support\Collection;

/**
 * Arma la evolución de cada parámetro que se repite entre estudios: la
 * curva de "Glucemia" a través de todos los análisis de un paciente, no
 * solo dentro de uno.
 *
 * Mismo rol que `SeriesDeMediciones` -las cuentas van en PHP, nunca en
 * SQL, porque `valor` está cifrado- pero con una diferencia real: acá se
 * **agrupa por `parametro_hash`**, la columna en claro, y no por el nombre
 * descifrado. Con cientos de resultados a lo largo de los años, comparar el
 * hash es gratis; descifrar cada fila solo para decidir a qué grupo
 * pertenece no lo es, y es exactamente para lo que existe esa columna (ver
 * la migración de `resultados_estudio`).
 *
 * Un resultado no numérico ("Positivo", "No reactivo") no entra a ninguna
 * serie: `ResultadoEstudio::valorNumerico()` es quien decide, y esta clase
 * no lo vuelve a evaluar.
 */
class SeriesDeResultados
{
    /**
     * @param  Collection<int, ResultadoEstudio>  $resultados  con su `estudio` ya cargado
     * @return list<array<string, mixed>>
     */
    public function armar(Collection $resultados): array
    {
        return array_values($resultados
            ->filter(fn (ResultadoEstudio $r): bool => $r->valorNumerico() !== null)
            ->groupBy(fn (ResultadoEstudio $r): string => (string) $r->getAttribute('parametro_hash'))
            /*
             * Los grupos de un solo punto no se grafican -ver la misma
             * regla en `mediciones/Index.vue` y `enfermedades/Index.vue`-:
             * una línea de un punto no es una evolución.
             */
            ->filter(fn (Collection $grupo): bool => $grupo->count() > 1)
            ->map(function (Collection $grupo): array {
                /** @var ResultadoEstudio $primero */
                $primero = $grupo->first();

                $ordenados = $grupo->sortBy(
                    fn (ResultadoEstudio $r): string => $r->estudio?->fecha->format('Ymd') ?? '',
                );

                $valores = $ordenados->map(fn (ResultadoEstudio $r): float => (float) $r->valorNumerico());

                /** @var ResultadoEstudio $ultimo */
                $ultimo = $ordenados->last();

                return [
                    'parametro' => $primero->parametro,
                    'unidad' => $primero->unidad ?? '',
                    // Para ordenar las series afuera, sin volver a mirar
                    // adentro de `puntos` -ahí `array_key_last` no puede
                    // probarle a PHPStan que la lista nunca está vacía-.
                    'fechaMasReciente' => $ultimo->estudio?->fecha->toIso8601String() ?? '',
                    'resumen' => [
                        'cantidad' => $ordenados->count(),
                        'minimo' => $this->formatear($valores->min()),
                        'maximo' => $this->formatear($valores->max()),
                        'promedio' => $this->formatear($valores->avg()),
                    ],
                    'puntos' => $ordenados
                        ->map(fn (ResultadoEstudio $r): array => [
                            'fechaIso' => $r->estudio?->fecha->toIso8601String() ?? '',
                            'fechaVisible' => $r->estudio?->fecha->format('d/m/Y') ?? '',
                            'valor' => (float) $r->valorNumerico(),
                            'valorSecundario' => null,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            // La evolución más reciente primero.
            ->sortByDesc(fn (array $serie): string => $serie['fechaMasReciente'])
            ->values()
            // `fechaMasReciente` era solo para ordenar: no es un dato que
            // necesite el frontend, así que no sale de acá.
            ->map(fn (array $serie): array => array_diff_key($serie, ['fechaMasReciente' => null]))
            ->all());
    }

    /**
     * A diferencia de `TipoMedicion::formatear()`, acá no hay una columna
     * `decimales` que declare cuántos mostrar -un resultado de laboratorio
     * no tiene esa configuración-. Se redondea a dos como techo -es lo que
     * necesita el promedio para no salir con siete cifras- y se recortan
     * los ceros de más: "90,00" queda "90", "90,50" queda "90,5".
     */
    private function formatear(float $valor): string
    {
        $texto = rtrim(rtrim(number_format($valor, 2, ',', '.'), '0'), ',');

        return $texto === '' || $texto === '-' ? '0' : $texto;
    }
}
