<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AvanceDePedidoGuardarRequest;
use App\Models\PedidoProcedimiento;
use App\Models\Tratamiento;
use App\Services\SeguimientoDePedido;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * El seguimiento de un pedido: guardar el avance del que está en curso (al
 * completar la última etapa pasa solo al historial) y volver a abrir el último.
 */
class AvanceDePedidoController extends Controller
{
    public function update(AvanceDePedidoGuardarRequest $peticion, Tratamiento $tratamiento, SeguimientoDePedido $seguimiento): RedirectResponse
    {
        Gate::authorize('update', $tratamiento);

        /** @var list<array{etapa_id: int|string, completada: bool, completada_el?: string|null, observaciones?: string|null}> $pasos */
        $pasos = $peticion->validated('pasos');

        $cerrado = $seguimiento->guardar($tratamiento, (string) $peticion->validated('iniciado_el'), $pasos);

        return back()->with('exito', $cerrado
            ? 'Pedido completo: quedó en el historial.'
            : 'Se guardó el avance.');
    }

    public function reabrir(PedidoProcedimiento $pedidoProcedimiento, SeguimientoDePedido $seguimiento): RedirectResponse
    {
        Gate::authorize('update', $pedidoProcedimiento);

        return $seguimiento->reabrir($pedidoProcedimiento)
            ? back()->with('exito', 'Se volvió a abrir el pedido.')
            : back()->with('error', 'Solo se puede volver a abrir el último pedido, y si no hay otro en curso.');
    }
}
