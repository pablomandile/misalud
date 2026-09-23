<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Concerns\DeCatalogo;
use App\Contracts\CifraDatos;
use App\Contracts\EsCatalogo;
use App\Database\Eloquent\ConsultaVigilada;
use App\Policies\CatalogoPolicy;
use Database\Factories\MedicoFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un médico del catálogo del usuario.
 *
 * **Primer catálogo, y por lo tanto la plantilla**: centros, medicamentos y
 * vacunas se arman igual, y `tipos_medicion` (Etapa 6) también. Las tres
 * piezas que lo definen —`EsCatalogo` + `DeCatalogo` + `CatalogoPolicy`—
 * están pensadas para copiarse tal cual.
 *
 * Cuelga del USUARIO y no de un paciente: el mismo médico atiende a toda la
 * familia que uno administra.
 *
 * @property int $id
 * @property int|null $usuario_id
 * @property string $nombre
 * @property string|null $especialidad
 * @property string|null $telefono
 * @property string|null $email
 * @property string|null $notas
 */
#[UsePolicy(CatalogoPolicy::class)]
class Medico extends Model implements CifraDatos, EsCatalogo
{
    use CifraCampos;
    use DeCatalogo;

    /** @use HasFactory<MedicoFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'medicos';

    /*
     * `usuario_id` NO es fillable, por lo mismo que `paciente_id` no lo es en
     * el dominio clínico: es la columna de la que depende toda la
     * autorización. La pone el controlador desde la sesión, nunca un
     * formulario.
     */
    protected $fillable = [
        'nombre',
        'especialidad',
        'telefono',
        'email',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nombre' => 'encrypted',
            'especialidad' => 'encrypted',
            'telefono' => 'encrypted',
            'email' => 'encrypted',
            'notas' => 'encrypted',
        ];
    }

    public function indicesCiegos(): array
    {
        return ['nombre' => 'nombre_hash'];
    }
}
