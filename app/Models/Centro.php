<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Concerns\DeCatalogo;
use App\Contracts\CifraDatos;
use App\Contracts\EsCatalogo;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\TipoCentro;
use App\Policies\CatalogoPolicy;
use Database\Factories\CentroFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un centro del catálogo del usuario: consultorio, clínica, laboratorio.
 *
 * Copiado del patrón de `Medico` (ver ese modelo). Lo único que suma es la
 * relación `medicos()`: dónde atiende cada uno.
 *
 * @property int $id
 * @property int|null $usuario_id
 * @property string $nombre
 * @property TipoCentro $tipo
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $whatsapp
 * @property string|null $email
 * @property string|null $web
 * @property string|null $horarios
 * @property string|null $notas
 */
#[UsePolicy(CatalogoPolicy::class)]
class Centro extends Model implements CifraDatos, EsCatalogo
{
    use CifraCampos;
    use DeCatalogo;

    /** @use HasFactory<CentroFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'centros';

    /*
     * `usuario_id` NO es fillable, mismo motivo que en `Medico`.
     */
    protected $fillable = [
        'nombre',
        'tipo',
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
            'tipo' => TipoCentro::class,
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
     * Los médicos que atienden en este centro.
     *
     * @return BelongsToMany<Medico, $this>
     */
    public function medicos(): BelongsToMany
    {
        return $this->belongsToMany(Medico::class, 'centro_medico')->withTimestamps();
    }

    /**
     * El enlace para escribirle por WhatsApp, o null si no hay número.
     *
     * `wa.me` exige el número internacional sin `+`. El formulario pide el
     * número argentino con código de área, sin el 0 ni el 15 ("11 2345 6789"),
     * que es como la gente lo tiene anotado: si no empieza con 54, se le
     * antepone 549, el prefijo de un celular de Argentina. Uno que ya viene
     * con su código de país (otro país, o "+54 9 …") se respeta tal cual.
     */
    public function enlaceWhatsapp(): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $this->whatsapp) ?? '';

        if ($digitos === '') {
            return null;
        }

        $internacional = str_starts_with((string) $this->whatsapp, '+') || str_starts_with($digitos, '54')
            ? $digitos
            : '549'.ltrim($digitos, '0');

        return 'https://wa.me/'.$internacional;
    }
}
