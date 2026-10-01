<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Concerns\TieneAdjuntos;
use App\Contracts\CifraDatos;
use App\Contracts\TieneArchivos;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\EstadoReceta;
use App\Policies\RecetaPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\RecetaFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una receta que llegó por mail.
 *
 * La receta **es su archivo**: el PDF cuelga como un `Adjunto` de tipo `Receta`
 * y esta fila es la ficha del mail del que salió. Por eso es el séptimo dueño de
 * archivos del proyecto y declara `TieneArchivos` igual que los otros seis.
 *
 * Pertenece a **quien administra la casilla** (`usuario_id`) y no a un paciente:
 * un mail de la farmacia no dice de quién es la receta, y adivinarlo sería
 * cargársela a un familiar equivocado. Ver la migración para el detalle.
 *
 * @property int $id
 * @property int $usuario_id
 * @property int|null $cuenta_mail_id
 * @property string $message_id
 * @property string $remitente
 * @property string|null $asunto
 * @property CarbonImmutable $fecha_recepcion
 * @property int $vigencia_dias
 * @property EstadoReceta $estado
 */
#[UsePolicy(RecetaPolicy::class)]
class Receta extends Model implements CifraDatos, TieneArchivos
{
    use CifraCampos;

    /** @use HasFactory<RecetaFactory> */
    use HasFactory;

    use SoftDeletes;
    use TieneAdjuntos;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'recetas';

    /*
     * `usuario_id` NO es fillable: de esa FK cuelga toda la autorización. La
     * receta se crea por la relación, `$usuario->recetas()->create(...)`.
     */
    protected $fillable = [
        'cuenta_mail_id',
        'message_id',
        'remitente',
        'asunto',
        'fecha_recepcion',
        'vigencia_dias',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'message_id' => 'encrypted',
            'remitente' => 'encrypted',
            'asunto' => 'encrypted',
            'fecha_recepcion' => 'immutable_datetime',
            'vigencia_dias' => 'integer',
            'estado' => EstadoReceta::class,
        ];
    }

    public function indicesCiegos(): array
    {
        return ['message_id' => 'message_id_hash'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * De qué casilla vino.
     *
     * ⚠️ Con `withTrashed()` no haría falta —`cuentas_mail` no tiene soft
     * deletes—, pero puede ser `null`: borrar la casilla deja la receta sin
     * vínculo y eso es lo correcto (ver la migración).
     *
     * @return BelongsTo<CuentaMail, $this>
     */
    public function cuentaMail(): BelongsTo
    {
        return $this->belongsTo(CuentaMail::class, 'cuenta_mail_id');
    }

    /**
     * Cuándo deja de servir.
     *
     * Se cuenta desde que **llegó el mail**, no desde que se importó: si el
     * servidor estuvo caído tres días, la receta no gana tres días de vida.
     */
    public function vence(): CarbonImmutable
    {
        return $this->fecha_recepcion->addDays($this->vigencia_dias);
    }

    /**
     * Derivado, nunca guardado. Ver `EstadoReceta`.
     */
    public function estaVencida(): bool
    {
        return $this->vence()->isPast();
    }

    /**
     * Lo que la bandeja ofrece de verdad: sin usar y sin vencer.
     */
    public function estaDisponible(): bool
    {
        return $this->estado === EstadoReceta::Disponible && ! $this->estaVencida();
    }
}
