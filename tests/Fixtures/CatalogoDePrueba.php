<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Concerns\CifraCampos;
use App\Concerns\DeCatalogo;
use App\Concerns\TieneAdjuntos;
use App\Contracts\CifraDatos;
use App\Contracts\EsCatalogo;
use App\Database\Eloquent\ConsultaVigilada;
use App\Policies\CatalogoPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Un catálogo de juguete que además acepta adjuntos, para probar que la
 * autorización delegada de `AdjuntoPolicy` funciona sobre algo que **no
 * cuelga de ningún paciente**.
 *
 * Existe como fixture y no como modelo de la app porque el primer catálogo
 * real con archivos es `medicamentos` (su prospecto), que llega en el paso
 * 5.3. Sin esto, la rama que más importa de la delegación se quedaría sin
 * cubrir justo hasta el commit que la necesita.
 *
 * Su tabla la crea el propio test.
 *
 * @property string|null $nombre
 */
#[UsePolicy(CatalogoPolicy::class)]
class CatalogoDePrueba extends Model implements CifraDatos, EsCatalogo
{
    use CifraCampos;
    use DeCatalogo;
    use TieneAdjuntos;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'catalogos_de_prueba';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    public function indicesCiegos(): array
    {
        return ['nombre' => 'nombre_hash'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['nombre' => 'encrypted'];
    }
}
