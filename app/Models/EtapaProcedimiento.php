<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Database\Eloquent\ConsultaVigilada;
use App\Policies\RegistroClinicoPolicy;
use Database\Factories\EtapaProcedimientoFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una etapa del procedimiento para pedir un medicamento de la ficha («Pedir la
 * receta», «Autorizar en la obra social»). Solo el nombre: lo que pasó cada vez
 * va en el paso del pedido (`PasoPedido`).
 *
 * Llega a su paciente por el tratamiento, como la bitácora por su enfermedad.
 *
 * @property int $id
 * @property int $tratamiento_id
 * @property int $orden
 * @property string $nombre
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class EtapaProcedimiento extends Model implements CifraDatos, PerteneceAPaciente
{
    use CifraCampos;

    /** @use HasFactory<EtapaProcedimientoFactory> */
    use HasFactory;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'etapas_procedimiento';

    // `tratamiento_id` NO es fillable: se crea por la relación del tratamiento.
    protected $fillable = [
        'orden',
        'nombre',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nombre' => 'encrypted',
            'orden' => 'integer',
        ];
    }

    public function indicesCiegos(): array
    {
        return [];
    }

    /**
     * @return BelongsTo<Tratamiento, $this>
     */
    public function tratamiento(): BelongsTo
    {
        return $this->belongsTo(Tratamiento::class);
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->tratamiento?->pacienteDelRegistro();
    }
}
