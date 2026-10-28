<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Concerns\DeCatalogo;
use App\Concerns\TieneAdjuntos;
use App\Contracts\CifraDatos;
use App\Contracts\EsCatalogo;
use App\Contracts\TieneArchivos;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\TipoCentro;
use App\Policies\CatalogoPolicy;
use App\Support\Whatsapp;
use Database\Factories\CentroFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un centro del catálogo del usuario: consultorio, clínica, laboratorio.
 *
 * Copiado del patrón de `Medico` (ver ese modelo). Lo que suma es la
 * relación `medicos()` —dónde atiende cada uno— y sus imágenes (el edificio, el
 * QR de WhatsApp): es el segundo catálogo con archivos, después del prospecto
 * de un medicamento, y como aquel no necesitó tocar la autorización —subir una
 * imagen es editar el centro (`AdjuntoPolicy`)—.
 *
 * @property int $id
 * @property int|null $usuario_id
 * @property string $nombre
 * @property TipoCentro $tipo
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $whatsapp
 * @property string|null $email
 * @property string|null $email_recetas
 * @property string|null $web
 * @property string|null $horarios
 * @property string|null $notas
 */
#[UsePolicy(CatalogoPolicy::class)]
class Centro extends Model implements CifraDatos, EsCatalogo, TieneArchivos
{
    use CifraCampos;
    use DeCatalogo;

    /** @use HasFactory<CentroFactory> */
    use HasFactory;

    use SoftDeletes;
    use TieneAdjuntos;

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
        'email_recetas',
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
            'email_recetas' => 'encrypted',
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
     * A dónde se le mandan recetas, órdenes y estudios: el correo de recetas si
     * lo cargaron, y si no el general. `null` si no tiene ninguno.
     */
    public function emailParaEnvios(): ?string
    {
        return filled($this->email_recetas) ? $this->email_recetas : (filled($this->email) ? $this->email : null);
    }

    /**
     * El enlace para escribirle por WhatsApp (ver `Whatsapp::enlace()`).
     */
    public function enlaceWhatsapp(): ?string
    {
        return Whatsapp::enlace($this->whatsapp);
    }
}
