<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\PerteneceAPaciente;
use App\Policies\RegistroClinicoPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\PedidoProcedimientoFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una vuelta del procedimiento de pedido de un medicamento. Está en curso
 * mientras `completado_el` es NULL; al completarse la última etapa se cierra y
 * pasa al historial. Hay como mucho uno en curso por tratamiento, y lo garantiza
 * `SeguimientoDePedido`, que es el único que los crea.
 *
 * No cifra nada: fechas y una FK. El contenido (los nombres y las observaciones)
 * vive en sus pasos.
 *
 * @property int $id
 * @property int $tratamiento_id
 * @property CarbonImmutable $iniciado_el
 * @property CarbonImmutable|null $completado_el
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class PedidoProcedimiento extends Model implements PerteneceAPaciente
{
    /** @use HasFactory<PedidoProcedimientoFactory> */
    use HasFactory;

    protected $table = 'pedidos_procedimiento';

    // `tratamiento_id` NO es fillable: se crea por la relación del tratamiento.
    protected $fillable = [
        'iniciado_el',
        'completado_el',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'iniciado_el' => 'date',
            'completado_el' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Tratamiento, $this>
     */
    public function tratamiento(): BelongsTo
    {
        return $this->belongsTo(Tratamiento::class);
    }

    /**
     * @return HasMany<PasoPedido, $this>
     */
    public function pasos(): HasMany
    {
        return $this->hasMany(PasoPedido::class, 'pedido_id')->orderBy('orden')->orderBy('id');
    }

    public function enCurso(): bool
    {
        return $this->completado_el === null;
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->tratamiento?->pacienteDelRegistro();
    }
}
