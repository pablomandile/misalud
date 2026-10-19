<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Concerns\TieneAdjuntos;
use App\Contracts\CifraDatos;
use App\Contracts\TieneArchivos;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\MotivoDeRevision;
use App\Enums\RolPaciente;
use App\Enums\TipoDocumentoPorMail;
use App\Policies\DocumentoPorRevisarPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\DocumentoPorRevisarFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que llegó al buzón de un remitente conocido y no se pudo guardar solo (ver
 * `MotivoDeRevision`). Décimo dueño de archivos: los adjuntos del mail cuelgan
 * de acá hasta que alguien lo resuelve, y entonces se pasan al registro nuevo.
 *
 * ⚠️ **No implementa `PerteneceAPaciente`**: puede no saberse de qué ficha es
 * (el motivo es justamente ese), y `RegistroClinicoPolicy` niega un paciente
 * nulo. Tiene su Policy propia, que también mira `usuario_id`.
 *
 * @property int $id
 * @property int|null $paciente_id
 * @property int|null $usuario_id
 * @property string $remitente
 * @property string|null $asunto
 * @property MotivoDeRevision $motivo
 * @property TipoDocumentoPorMail|null $tipo_sugerido
 * @property CarbonImmutable $fecha_recepcion
 */
#[UsePolicy(DocumentoPorRevisarPolicy::class)]
class DocumentoPorRevisar extends Model implements CifraDatos, TieneArchivos
{
    use CifraCampos;

    /** @use HasFactory<DocumentoPorRevisarFactory> */
    use HasFactory;

    use TieneAdjuntos;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'documentos_por_revisar';

    /*
     * `paciente_id` y `usuario_id` NO son fillable: de ellos cuelga quién lo
     * ve. Los pone el procesador del buzón con `forceFill`, el único camino.
     */
    protected $fillable = [
        'remitente',
        'asunto',
        'motivo',
        'tipo_sugerido',
        'fecha_recepcion',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'remitente' => 'encrypted',
            'asunto' => 'encrypted',
            'motivo' => MotivoDeRevision::class,
            'tipo_sugerido' => TipoDocumentoPorMail::class,
            'fecha_recepcion' => 'immutable_datetime',
        ];
    }

    public function indicesCiegos(): array
    {
        return [];
    }

    /**
     * @return BelongsTo<Paciente, $this>
     */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * La regla de quién lo ve, en un solo lugar: la usan la Policy (de a uno)
     * y el listado (en una consulta).
     *
     * - El mail vino del correo de esta cuenta, o
     * - es de una ficha que esta persona puede editar (propietario o cuidador).
     *   Un lector no: resolverlo es cargar datos en la ficha.
     *
     * ⚠️ Una sola clausura con el OR adentro: sin agrupar, el `orWhere` se
     * mezclaría con cualquier otro filtro encadenado y devolvería de más.
     *
     * @param  Builder<DocumentoPorRevisar>  $consulta
     * @return Builder<DocumentoPorRevisar>
     */
    public function scopeVisiblesPara(Builder $consulta, User $usuario): Builder
    {
        return $consulta->where(fn (Builder $ambito) => $ambito
            ->where('usuario_id', $usuario->id)
            ->orWhereIn('paciente_id', $usuario->pacientes()
                ->wherePivotIn('rol', [RolPaciente::Propietario->value, RolPaciente::Cuidador->value])
                ->getQuery()
                ->select('pacientes.id')),
        );
    }
}
