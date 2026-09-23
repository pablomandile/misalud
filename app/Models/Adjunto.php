<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\TipoAdjunto;
use App\Policies\AdjuntoPolicy;
use Database\Factories\AdjuntoFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un archivo de la historia clínica: un PDF o una imagen.
 *
 * Cuelga polimórficamente de lo que sea que lo tenga, y el `tipo` dice para qué
 * es. El contenido vive cifrado en el disco privado (ver `ArchivoService`) y se
 * sirve siempre por controlador, nunca por URL pública.
 *
 * **No sabe de quién es, y no tiene por qué saberlo.** La autorización se
 * delega entera en su `adjuntable` (ver `AdjuntoPolicy`): así sirve igual
 * colgado de un paciente, de una cobertura o de un catálogo, sin que este
 * modelo conozca ninguno de los tres.
 *
 * @property int $id
 * @property string $adjuntable_type
 * @property int $adjuntable_id
 * @property TipoAdjunto $tipo
 * @property string $ruta
 * @property string $nombre_original
 * @property string|null $descripcion
 * @property string $mime
 * @property int $tamanio_bytes
 * @property int|null $duracion_segundos
 */
#[UsePolicy(AdjuntoPolicy::class)]
class Adjunto extends Model implements CifraDatos
{
    use CifraCampos;

    /** @use HasFactory<AdjuntoFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'adjuntos';

    /*
     * `adjuntable_type` y `adjuntable_id` NO son fillable, por el mismo motivo
     * por el que `paciente_id` tampoco lo es en el resto del dominio: de esa
     * referencia cuelga toda la autorización. El adjunto se crea por la
     * relación -`$estudio->adjuntos()->create(...)`-, no armando el vínculo a
     * mano desde un request.
     *
     * `ruta`, `mime` y `tamanio_bytes` sí lo son, pero nunca salen de la
     * entrada del usuario: los compone `ArchivoService` a partir del archivo
     * real. El FormRequest de cada pantalla valida `archivo`, `tipo` y
     * `descripcion`, y nada más.
     */
    protected $fillable = [
        'tipo',
        'ruta',
        'nombre_original',
        'descripcion',
        'mime',
        'tamanio_bytes',
        'duracion_segundos',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoAdjunto::class,
            'nombre_original' => 'encrypted',
            'descripcion' => 'encrypted',
            'tamanio_bytes' => 'integer',
            'duracion_segundos' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function adjuntable(): MorphTo
    {
        return $this->morphTo();
    }

    public function esImagen(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function esPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }
}
