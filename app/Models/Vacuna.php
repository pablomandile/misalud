<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Concerns\DeCatalogo;
use App\Contracts\CifraDatos;
use App\Contracts\EsCatalogo;
use App\Database\Eloquent\ConsultaVigilada;
use App\Policies\CatalogoPolicy;
use Database\Factories\VacunaFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una vacuna del catálogo del usuario.
 *
 * Cuarto catálogo, copiado de `Medico` sin ninguna decisión nueva: es el más
 * chico de los cuatro, solo nombre y notas. **No lleva `TieneAdjuntos`**: el
 * comprobante de una dosis aplicada no cuelga de la vacuna del catálogo
 * -que es solo el nombre, compartido entre dosis y pacientes-, sino de
 * `aplicaciones_vacuna` (Etapa 11), que es el registro clínico real.
 *
 * @property int $id
 * @property int|null $usuario_id
 * @property string $nombre
 * @property string|null $notas
 */
#[UsePolicy(CatalogoPolicy::class)]
class Vacuna extends Model implements CifraDatos, EsCatalogo
{
    use CifraCampos;
    use DeCatalogo;

    /** @use HasFactory<VacunaFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'vacunas';

    protected $fillable = [
        'nombre',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nombre' => 'encrypted',
            'notas' => 'encrypted',
        ];
    }

    public function indicesCiegos(): array
    {
        return ['nombre' => 'nombre_hash'];
    }
}
