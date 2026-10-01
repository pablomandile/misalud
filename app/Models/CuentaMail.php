<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Database\Eloquent\ConsultaVigilada;
use App\Policies\CuentaMailPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\CuentaMailFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La casilla de correo de la que se importan las recetas.
 *
 * Cuelga del **usuario** y no de un paciente: es una sola casilla y de ahí
 * salen las recetas de toda la familia que uno administra.
 *
 * ## ⚠️ `password` es de solo escritura
 *
 * Nunca viaja al navegador —ni enmascarada, ni con su largo real—, porque el
 * único motivo para mandarla sería precargar el formulario de edición y eso
 * significa poner una contraseña en un HTML que después queda en la caché del
 * navegador y en el historial de la pestaña. Al editar, el campo vacío
 * significa **"dejá la que está"**; lo hace cumplir
 * `CuentaMailGuardarRequest`, y `$hidden` de acá es la red por si alguna
 * respuesta futura serializa el modelo entero por descuido.
 *
 * Eso es también lo que decide la forma de la prueba de conexión: se prueba
 * **lo guardado** y no lo que hay escrito en el formulario, porque el
 * formulario de edición no tiene la contraseña.
 *
 * @property int $id
 * @property int $usuario_id
 * @property string $host
 * @property int $puerto
 * @property string $direccion
 * @property string $password
 * @property string $carpeta
 * @property list<string>|null $filtros
 * @property CarbonImmutable|null $sincronizado_hasta
 */
#[UsePolicy(CuentaMailPolicy::class)]
class CuentaMail extends Model implements CifraDatos
{
    use CifraCampos;

    /** @use HasFactory<CuentaMailFactory> */
    use HasFactory;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'cuentas_mail';

    /*
     * `usuario_id` NO es fillable: lo pone el controlador desde la sesión,
     * igual que en los catálogos.
     */
    protected $fillable = [
        'host',
        'puerto',
        'direccion',
        'password',
        'carpeta',
        'filtros',
    ];

    /** @var list<string> */
    protected $hidden = ['password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direccion' => 'encrypted',
            'password' => 'encrypted',
            'filtros' => 'encrypted:array',
            'puerto' => 'integer',
            'sincronizado_hasta' => 'immutable_datetime',
        ];
    }

    public function indicesCiegos(): array
    {
        return ['direccion' => 'direccion_hash'];
    }

    /**
     * El dueño de la casilla.
     *
     * Se llama `usuario()` como en los cinco catálogos. Por eso la columna del
     * login se llama `direccion` y no `usuario`: un atributo con el nombre de
     * una relación la tapa en silencio (ver la migración).
     *
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * Las recetas que entraron por esta casilla.
     *
     * ⚠️ Es `nullOnDelete`, no `cascade`: borrar la casilla **no** se lleva las
     * recetas ya importadas, que son documentos de la persona. Lo que se pierde
     * es saber de dónde vinieron.
     *
     * @return HasMany<Receta, $this>
     */
    public function recetas(): HasMany
    {
        return $this->hasMany(Receta::class, 'cuenta_mail_id');
    }

    /**
     * Cómo se cifra el transporte, deducido del puerto.
     *
     * 993 es IMAP sobre TLS desde el saludo; 143 arranca en texto plano y
     * sube con STARTTLS. Son los dos únicos puertos que la validación acepta,
     * justamente para que esta deducción no tenga casos raros.
     *
     * No hay forma de pedir "sin encriptación": una casilla de correo sin TLS
     * manda la contraseña en claro por la red.
     */
    public function encriptacion(): string
    {
        return $this->puerto === 143 ? 'tls' : 'ssl';
    }

    /**
     * Las direcciones de las que se importa, o `[]` si no hay filtro.
     *
     * Vacío significa **todo lo que haya en la carpeta**, y es una opción
     * válida: quien arma una regla en su correo para que las recetas caigan en
     * una carpeta propia ya filtró antes de que nosotros miremos.
     *
     * @return list<string>
     */
    public function remitentesAceptados(): array
    {
        return $this->filtros ?? [];
    }
}
