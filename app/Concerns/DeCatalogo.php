<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La implementación de `App\Contracts\EsCatalogo`.
 *
 * Uso:
 *
 *     #[UsePolicy(CatalogoPolicy::class)]
 *     class Medico extends Model implements CifraDatos, EsCatalogo
 *     {
 *         use CifraCampos;
 *         use DeCatalogo;
 *
 *         protected static string $builder = ConsultaVigilada::class;
 *
 *         public function indicesCiegos(): array
 *         {
 *             return ['nombre' => 'nombre_hash'];
 *         }
 *     }
 *
 * Convive con `CifraCampos` sin fricción: uno protege el contenido, el otro
 * resuelve de quién es y quién lo puede tocar.
 */
trait DeCatalogo
{
    /**
     * Por defecto `nombre`. `Medicamento` lo pisa con `nombre_comercial`.
     */
    public function columnaNombre(): string
    {
        return 'nombre';
    }

    public function nombreVisible(): string
    {
        $nombre = $this->getAttribute($this->columnaNombre());

        return is_string($nombre) ? $nombre : '';
    }

    public function esSemilla(): bool
    {
        return $this->getAttribute('usuario_id') === null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
