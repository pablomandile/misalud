<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Concerns\DeCatalogo;
use App\Concerns\TieneAdjuntos;
use App\Contracts\CifraDatos;
use App\Contracts\EsCatalogo;
use App\Database\Eloquent\ConsultaVigilada;
use App\Policies\CatalogoPolicy;
use Database\Factories\MedicamentoFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un medicamento del catálogo del usuario, con su prospecto en PDF.
 *
 * Tercer catálogo, copiado de `Medico` (ver ese modelo) con una sola
 * diferencia real: el nombre visible es `nombre_comercial`, no `nombre` -es
 * lo que trae la caja, no la droga-, así que pisa `columnaNombre()`. De eso
 * dependen el orden del listado, el índice ciego y el mensaje de "ya existe
 * uno así" (ver `DeCatalogo`).
 *
 * **Es el primer catálogo que suma `TieneAdjuntos`**: el prospecto es un
 * adjunto tipo `Prospecto` colgado de acá, no de un paciente. La
 * autorización no necesitó ni una línea nueva -`AdjuntoPolicy` ya delega en
 * `CatalogoPolicy` desde que se resolvió la Etapa 3-, que era justo lo que
 * esa refactorización estaba anticipando.
 *
 * @property int $id
 * @property int|null $usuario_id
 * @property string $nombre_comercial
 * @property string|null $droga
 * @property string|null $para_que_sirve
 * @property string|null $notas
 */
#[UsePolicy(CatalogoPolicy::class)]
class Medicamento extends Model implements CifraDatos, EsCatalogo
{
    use CifraCampos;
    use DeCatalogo;

    /** @use HasFactory<MedicamentoFactory> */
    use HasFactory;

    use SoftDeletes;
    use TieneAdjuntos;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'medicamentos';

    protected $fillable = [
        'nombre_comercial',
        'droga',
        'para_que_sirve',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nombre_comercial' => 'encrypted',
            'droga' => 'encrypted',
            'para_que_sirve' => 'encrypted',
            'notas' => 'encrypted',
        ];
    }

    public function columnaNombre(): string
    {
        return 'nombre_comercial';
    }

    public function indicesCiegos(): array
    {
        return ['nombre_comercial' => 'nombre_hash'];
    }
}
