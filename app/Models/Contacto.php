<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\TipoContacto;
use App\Policies\ContactoPolicy;
use Database\Factories\ContactoFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quién se le manda documentación. Ver la migración para por qué no es un
 * catálogo —la identidad de un contacto es su dirección, no su nombre— y por qué
 * no tiene soft deletes.
 *
 * @property int $id
 * @property int $usuario_id
 * @property string $nombre
 * @property string $email
 * @property TipoContacto $tipo
 */
#[UsePolicy(ContactoPolicy::class)]
class Contacto extends Model implements CifraDatos
{
    use CifraCampos;

    /** @use HasFactory<ContactoFactory> */
    use HasFactory;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'contactos';

    // `usuario_id` NO es fillable: se crea por la relación del usuario.
    protected $fillable = ['nombre', 'email', 'tipo'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nombre' => 'encrypted',
            'email' => 'encrypted',
            'tipo' => TipoContacto::class,
        ];
    }

    public function indicesCiegos(): array
    {
        return ['email' => 'email_hash'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
