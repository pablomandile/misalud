<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\EstadoEnvio;
use App\Policies\EnvioPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un envío de documentación: el registro de algo que pasó.
 *
 * Todo lo que describe el envío es una **foto del momento** —la dirección, el
 * nombre del destinatario, los nombres de los archivos—, no una referencia a algo
 * que después se puede editar o borrar. Ver la migración.
 *
 * No hay factory: un envío solo existe porque `EnviadorDeDocumentos` intentó
 * mandar algo, y es el único camino de escritura de la tabla. Los tests lo
 * producen así, mandando de verdad contra `Mail::fake()`.
 *
 * @property int $id
 * @property int $usuario_id
 * @property int|null $contacto_id
 * @property string $destinatario
 * @property string $destinatario_nombre
 * @property string $asunto
 * @property string|null $cuerpo
 * @property EstadoEnvio $estado
 * @property CarbonImmutable $created_at
 */
#[UsePolicy(EnvioPolicy::class)]
class Envio extends Model implements CifraDatos
{
    use CifraCampos;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'envios';

    // `usuario_id` NO es fillable: se crea por la relación del usuario.
    protected $fillable = [
        'contacto_id',
        'destinatario',
        'destinatario_nombre',
        'asunto',
        'cuerpo',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'destinatario' => 'encrypted',
            'destinatario_nombre' => 'encrypted',
            'asunto' => 'encrypted',
            'cuerpo' => 'encrypted',
            'estado' => EstadoEnvio::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * @return BelongsTo<Contacto, $this>
     */
    public function contacto(): BelongsTo
    {
        // Puede ser `null`: borrar el contacto lo desvincula. A quién se le
        // mandó sigue en `destinatario`, que es una foto del momento.
        return $this->belongsTo(Contacto::class, 'contacto_id');
    }

    /**
     * @return HasMany<ArchivoEnviado, $this>
     */
    public function archivos(): HasMany
    {
        return $this->hasMany(ArchivoEnviado::class, 'envio_id');
    }
}
