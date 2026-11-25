<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PasoPedido;
use App\Models\PedidoProcedimiento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PasoPedido>
 */
class PasoPedidoFactory extends Factory
{
    protected $model = PasoPedido::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pedido_id' => PedidoProcedimiento::factory(),
            'etapa_procedimiento_id' => null,
            'orden' => 0,
            'nombre' => 'Pedir la receta',
            'completada' => false,
            'completada_el' => null,
            'observaciones' => null,
        ];
    }
}
