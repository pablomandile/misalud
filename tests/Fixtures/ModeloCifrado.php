<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Database\Eloquent\ConsultaVigilada;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de juguete para ejercitar CifraCampos sin depender de ninguna tabla
 * del dominio. Su tabla la crea el propio test.
 *
 * @property string|null $nombre
 * @property string|null $notas
 * @property string|null $nombre_hash
 */
class ModeloCifrado extends Model implements CifraDatos
{
    use CifraCampos;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'modelos_cifrados';

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
        return [
            'nombre' => 'encrypted',
            'notas' => 'encrypted',
        ];
    }
}
