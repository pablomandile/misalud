<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Database\Eloquent\ConsultaVigilada;
use App\Policies\RegistroClinicoPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\PasoPedidoFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El avance de una etapa en un pedido: si se completó, cuándo, y qué pasó.
 *
 * ⚠️ El nombre es una **foto del momento**, como la dirección de un envío: si
 * mañana se renombra o se borra la etapa, el historial sigue diciendo lo que se
 * hizo. `etapa_procedimiento_id` es solo el vínculo mientras la etapa exista.
 *
 * @property int $id
 * @property int $pedido_id
 * @property int|null $etapa_procedimiento_id
 * @property int $orden
 * @property string $nombre
 * @property bool $completada
 * @property CarbonImmutable|null $completada_el
 * @property string|null $observaciones
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class PasoPedido extends Model implements CifraDatos, PerteneceAPaciente
{
    use CifraCampos;

    /** @use HasFactory<PasoPedidoFactory> */
    use HasFactory;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'pasos_pedido';

    // `pedido_id` y `etapa_procedimiento_id` NO son fillable: los escribe `SeguimientoDePedido`.
    protected $fillable = [
        'orden',
        'nombre',
        'completada',
        'completada_el',
        'observaciones',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nombre' => 'encrypted',
            'observaciones' => 'encrypted',
            'orden' => 'integer',
            'completada' => 'boolean',
            'completada_el' => 'date',
        ];
    }

    public function indicesCiegos(): array
    {
        return [];
    }

    /**
     * @return BelongsTo<PedidoProcedimiento, $this>
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoProcedimiento::class, 'pedido_id');
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->pedido?->pacienteDelRegistro();
    }
}
