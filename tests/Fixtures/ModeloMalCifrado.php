<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo deliberadamente mal declarado: cifra `nombre` pero su tabla le pone un
 * UNIQUE encima, y además declara un índice ciego cuya columna de hash no
 * existe, y encima se olvida de declarar el builder vigilado.
 *
 * Existe para que el test de guardia demuestre que detecta el problema. Sin él,
 * la guardia pasaría en verde por no tener nada que revisar, que es la peor
 * clase de test verde.
 */
class ModeloMalCifrado extends Model implements CifraDatos
{
    use CifraCampos;

    protected $table = 'modelos_mal_cifrados';

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
