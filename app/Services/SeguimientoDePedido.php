<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EtapaProcedimiento;
use App\Models\PasoPedido;
use App\Models\PedidoProcedimiento;
use App\Models\Tratamiento;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * El procedimiento de pedido de un medicamento de la ficha: sus etapas, el
 * pedido en curso y el historial. Es el único lugar que arma y guarda el avance,
 * y por eso el único que crea pedidos: así hay como mucho uno en curso por
 * medicamento.
 *
 * - **El pedido en curso sigue a las etapas de hoy.** Una etapa agregada a mitad
 *   de un pedido aparece sin tildar; una que se sacó deja de figurar.
 * - **Con todas las etapas completadas, el pedido se cierra solo** y pasa al
 *   historial, con la fecha de fin más tardía. El formulario vuelve en blanco.
 * - **El historial no se toca**: cada paso lleva la foto del nombre de su etapa.
 */
class SeguimientoDePedido
{
    /**
     * Lo que necesita la solapa «Procedimiento» para un medicamento.
     *
     * @return array<string, mixed>
     */
    public function vista(Tratamiento $tratamiento): array
    {
        $etapas = $tratamiento->etapasProcedimiento()->get();
        $pedidos = $tratamiento->pedidosProcedimiento()->with('pasos')->get();

        $enCurso = $pedidos->first(fn (PedidoProcedimiento $p): bool => $p->enCurso());
        $pasosEnCurso = $enCurso === null
            ? collect()
            : $enCurso->pasos->keyBy('etapa_procedimiento_id');

        $historial = $pedidos
            ->reject(fn (PedidoProcedimiento $p): bool => $p->enCurso())
            ->sortByDesc(fn (PedidoProcedimiento $p): string => $this->ordenDeHistorial($p))
            ->values();

        return [
            'etapas' => $etapas->map(fn (EtapaProcedimiento $e): array => [
                'id' => $e->id,
                'nombre' => $e->nombre,
            ])->values()->all(),
            'enCurso' => $enCurso === null ? null : [
                'id' => $enCurso->id,
                'iniciadoEl' => $enCurso->iniciado_el->format('Y-m-d'),
            ],
            // Un paso por etapa de hoy, con lo que ya se anotó en el pedido en curso.
            'pasos' => $etapas->map(function (EtapaProcedimiento $e) use ($pasosEnCurso): array {
                $paso = $pasosEnCurso->get($e->id);
                assert($paso === null || $paso instanceof PasoPedido);

                return [
                    'etapaId' => $e->id,
                    'nombre' => $e->nombre,
                    'completada' => $paso->completada ?? false,
                    'completadaEl' => $paso?->completada_el?->format('Y-m-d'),
                    'observaciones' => $paso->observaciones ?? '',
                ];
            })->values()->all(),
            'historial' => $historial->map(fn (PedidoProcedimiento $p): array => [
                'id' => $p->id,
                'inicioVisible' => $p->iniciado_el->format('d/m/Y'),
                'finVisible' => $p->completado_el?->format('d/m/Y'),
                'pasos' => $p->pasos->map(fn (PasoPedido $paso): array => [
                    'id' => $paso->id,
                    'nombre' => $paso->nombre,
                    'completada' => $paso->completada,
                    'completadaElVisible' => $paso->completada_el?->format('d/m/Y'),
                    'observaciones' => $paso->observaciones,
                ])->values()->all(),
            ])->all(),
            // Solo el último, y solo si no hay otro en curso.
            'reabrible' => $enCurso === null ? $historial->first()?->id : null,
        ];
    }

    /**
     * Reemplaza las etapas por la lista que llegó, en ese orden: las que traen
     * id se renombran, las nuevas se crean y las que faltan se borran. El
     * historial no cambia (sus pasos se quedan con su foto y pierden el vínculo).
     *
     * @param  list<array{id?: int|string|null, nombre: string}>  $etapas
     */
    public function definirEtapas(Tratamiento $tratamiento, array $etapas): void
    {
        DB::transaction(function () use ($tratamiento, $etapas): void {
            $actuales = $tratamiento->etapasProcedimiento()->get()->keyBy('id');
            $quedan = [];

            foreach ($etapas as $orden => $datos) {
                $id = isset($datos['id']) && $datos['id'] !== '' ? (int) $datos['id'] : null;
                $etapa = $id === null ? null : $actuales->get($id);

                if ($etapa instanceof EtapaProcedimiento) {
                    $etapa->update(['orden' => $orden, 'nombre' => trim($datos['nombre'])]);
                } else {
                    $etapa = $tratamiento->etapasProcedimiento()->create(['orden' => $orden, 'nombre' => trim($datos['nombre'])]);
                }

                $quedan[] = $etapa->id;
            }

            $tratamiento->etapasProcedimiento()->whereNotIn('id', $quedan)->delete();
        });
    }

    /**
     * Guarda el avance del pedido en curso (lo crea si no había) y lo cierra si
     * quedaron todas las etapas completadas. Devuelve si se cerró.
     *
     * @param  list<array{etapa_id: int|string, completada: bool, completada_el?: string|null, observaciones?: string|null}>  $pasos
     */
    public function guardar(Tratamiento $tratamiento, string $iniciadoEl, array $pasos): bool
    {
        return DB::transaction(function () use ($tratamiento, $iniciadoEl, $pasos): bool {
            $etapas = $tratamiento->etapasProcedimiento()->get();

            $pedido = $this->enCurso($tratamiento)
                ?? $tratamiento->pedidosProcedimiento()->make();
            $pedido->setAttribute('iniciado_el', $iniciadoEl);
            $pedido->save();

            $existentes = $pedido->pasos()->get()->keyBy('etapa_procedimiento_id');
            $llegados = collect($pasos)->keyBy(fn (array $p): int => (int) $p['etapa_id']);
            $fechas = [];

            foreach ($etapas->values() as $orden => $etapa) {
                $datos = $llegados->get($etapa->id, []);
                $completada = (bool) ($datos['completada'] ?? false);
                $observaciones = trim((string) ($datos['observaciones'] ?? ''));

                $paso = $existentes->get($etapa->id);

                if (! $paso instanceof PasoPedido) {
                    $paso = $pedido->pasos()->make();
                    $paso->setAttribute('etapa_procedimiento_id', $etapa->id);
                }

                $paso->fill([
                    'orden' => $orden,
                    'nombre' => $etapa->nombre,
                    'completada' => $completada,
                    'completada_el' => $completada ? ($datos['completada_el'] ?? null) : null,
                    'observaciones' => $observaciones === '' ? null : $observaciones,
                ]);
                $paso->save();

                if ($completada && $paso->completada_el !== null) {
                    $fechas[] = $paso->completada_el;
                }
            }

            // Lo de una etapa que ya no está deja de figurar en el pedido en curso.
            $pedido->pasos()
                ->where(fn ($consulta) => $consulta
                    ->whereNull('etapa_procedimiento_id')
                    ->orWhereNotIn('etapa_procedimiento_id', $etapas->modelKeys()))
                ->delete();

            if ($fechas === [] || count($fechas) < $etapas->count()) {
                return false;
            }

            $pedido->setAttribute('completado_el', max($fechas));
            $pedido->save();

            return true;
        });
    }

    /**
     * La red para un tilde de más: vuelve a abrir el último pedido completado,
     * si no hay otro en curso. Devuelve si pudo.
     */
    public function reabrir(PedidoProcedimiento $pedido): bool
    {
        $tratamiento = $pedido->tratamiento;

        if ($pedido->enCurso() || $this->enCurso($tratamiento) !== null) {
            return false;
        }

        $ultimo = $tratamiento->pedidosProcedimiento()
            ->whereNotNull('completado_el')
            ->get()
            ->sortByDesc(fn (PedidoProcedimiento $p): string => $this->ordenDeHistorial($p))
            ->first();

        if ($ultimo?->id !== $pedido->id) {
            return false;
        }

        $pedido->setAttribute('completado_el', null);
        $pedido->save();

        return true;
    }

    public function enCurso(Tratamiento $tratamiento): ?PedidoProcedimiento
    {
        return $tratamiento->pedidosProcedimiento()->whereNull('completado_el')->first();
    }

    private function ordenDeHistorial(PedidoProcedimiento $pedido): string
    {
        $fin = $pedido->completado_el ?? CarbonImmutable::create(9999);

        return $fin->format('Ymd').str_pad((string) $pedido->id, 10, '0', STR_PAD_LEFT);
    }
}
