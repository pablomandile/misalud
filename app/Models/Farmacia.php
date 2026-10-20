<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Concerns\DeCatalogo;
use App\Contracts\CifraDatos;
use App\Contracts\EsCatalogo;
use App\Database\Eloquent\ConsultaVigilada;
use App\Policies\CatalogoPolicy;
use App\Support\Whatsapp;
use Database\Factories\FarmaciaFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una farmacia del catálogo del usuario: dónde queda, cómo contactarla y cuándo
 * atiende. Sexto catálogo, copiado del patrón de `Centro`.
 *
 * @property int $id
 * @property int|null $usuario_id
 * @property string $nombre
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $whatsapp
 * @property string|null $email
 * @property string|null $web
 * @property string|null $horarios
 * @property string|null $notas
 */
#[UsePolicy(CatalogoPolicy::class)]
class Farmacia extends Model implements CifraDatos, EsCatalogo
{
    use CifraCampos;
    use DeCatalogo;

    /** @use HasFactory<FarmaciaFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'farmacias';

    /*
     * `usuario_id` NO es fillable, mismo motivo que en `Medico`.
     */
    protected $fillable = [
        'nombre',
        'direccion',
        'telefono',
        'whatsapp',
        'email',
        'web',
        'horarios',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nombre' => 'encrypted',
            'direccion' => 'encrypted',
            'telefono' => 'encrypted',
            'whatsapp' => 'encrypted',
            'email' => 'encrypted',
            'web' => 'encrypted',
            'horarios' => 'encrypted',
            'notas' => 'encrypted',
        ];
    }

    public function indicesCiegos(): array
    {
        return ['nombre' => 'nombre_hash'];
    }

    /**
     * El enlace para escribirle por WhatsApp (ver `Whatsapp::enlace()`).
     */
    public function enlaceWhatsapp(): ?string
    {
        return Whatsapp::enlace($this->whatsapp);
    }
}
