<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Concerns\DeCatalogo;
use App\Contracts\CifraDatos;
use App\Contracts\EsCatalogo;
use App\Database\Eloquent\ConsultaVigilada;
use App\Policies\CatalogoPolicy;
use Database\Factories\TipoMedicionFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Qué se puede medir. El quinto catálogo, copiado del patrón (ver
 * `Medico`), y el primero donde las semillas son el caso NORMAL: los siete
 * tipos de siempre vienen cargados, y un tipo propio es la excepción.
 *
 * @property int $id
 * @property int|null $usuario_id
 * @property string|null $clave
 * @property string $nombre
 * @property string $unidad
 * @property string|null $unidad_secundaria
 * @property string|null $etiqueta_principal
 * @property string|null $etiqueta_secundaria
 * @property float|null $min_normal
 * @property float|null $max_normal
 * @property float|null $min_normal_secundario
 * @property float|null $max_normal_secundario
 * @property int $decimales
 */
#[UsePolicy(CatalogoPolicy::class)]
class TipoMedicion extends Model implements CifraDatos, EsCatalogo
{
    use CifraCampos;
    use DeCatalogo;

    /** @use HasFactory<TipoMedicionFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'tipos_medicion';

    /**
     * Las variables que el código reconoce por su cuenta.
     *
     * Solo las escribe el seeder: `clave` no es fillable, así que una
     * variable creada a mano queda en `null` y no la reconoce nadie —que es
     * lo correcto—. Ver la migración que agrega la columna.
     */
    public const CLAVE_PESO = 'peso';

    public const CLAVE_ALTURA = 'altura';

    protected $fillable = [
        'nombre',
        'unidad',
        'unidad_secundaria',
        'etiqueta_principal',
        'etiqueta_secundaria',
        'min_normal',
        'max_normal',
        'min_normal_secundario',
        'max_normal_secundario',
        'decimales',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nombre' => 'encrypted',
            'unidad' => 'encrypted',
            'unidad_secundaria' => 'encrypted',
            'etiqueta_principal' => 'encrypted',
            'etiqueta_secundaria' => 'encrypted',
            /*
             * Los rangos NO van cifrados: no son el dato clínico de nadie,
             * son una propiedad del tipo -que encima puede ser una semilla
             * compartida-. Y en claro se pueden leer como números; cifrados
             * volverían como texto, igual que `Medicion::$valor`.
             */
            'min_normal' => 'float',
            'max_normal' => 'float',
            'min_normal_secundario' => 'float',
            'max_normal_secundario' => 'float',
            'decimales' => 'integer',
        ];
    }

    public function indicesCiegos(): array
    {
        return ['nombre' => 'nombre_hash'];
    }

    /**
     * @return HasMany<Medicion, $this>
     */
    public function mediciones(): HasMany
    {
        return $this->hasMany(Medicion::class);
    }

    /**
     * ¿Este tipo se carga con dos números, como la presión?
     *
     * **Lo decide la etiqueta del segundo valor, no la unidad.** La etiqueta
     * es lo que el formulario necesita para poder rotular el campo: sin
     * "Diastólica" escrito en algún lado, no hay forma honesta de pedir el
     * segundo número. La unidad secundaria, en cambio, suele estar vacía
     * -en presión los dos son mmHg- y no distinguiría nada.
     */
    public function tieneValorSecundario(): bool
    {
        return filled($this->etiqueta_secundaria);
    }

    /** El rótulo del primer campo. "Valor" alcanza cuando hay uno solo. */
    public function etiquetaPrincipalVisible(): string
    {
        return $this->etiqueta_principal ?? 'Valor';
    }

    /** La unidad del segundo valor cae en la principal si no declara una. */
    public function unidadSecundariaVisible(): string
    {
        return $this->unidad_secundaria ?? $this->unidad;
    }

    /**
     * Formatea un número con los decimales que este tipo declara, en
     * castellano: coma decimal, punto de miles.
     */
    public function formatear(?float $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        return number_format($valor, $this->decimales, ',', '.');
    }
}
