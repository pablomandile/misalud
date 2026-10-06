<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Database\Eloquent\ConsultaVigilada;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un archivo que salió en un envío: una fila de `adjunto_envio`.
 *
 * Es un pivote con dos columnas propias —la foto del nombre y del tamaño—, y por
 * eso tiene modelo: el nombre de un archivo clínico va cifrado, y un cast necesita
 * un modelo donde vivir.
 *
 * @property int $id
 * @property int $envio_id
 * @property int|null $adjunto_id
 * @property string $nombre
 * @property int $tamanio_bytes
 */
class ArchivoEnviado extends Model implements CifraDatos
{
    use CifraCampos;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'adjunto_envio';

    protected $fillable = ['adjunto_id', 'nombre', 'tamanio_bytes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nombre' => 'encrypted',
            'tamanio_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Envio, $this>
     */
    public function envio(): BelongsTo
    {
        return $this->belongsTo(Envio::class, 'envio_id');
    }

    /**
     * El archivo, si todavía existe. Puede ser `null`: borrar el archivo no borra
     * que se mandó.
     *
     * @return BelongsTo<Adjunto, $this>
     */
    public function adjunto(): BelongsTo
    {
        return $this->belongsTo(Adjunto::class, 'adjunto_id');
    }
}
