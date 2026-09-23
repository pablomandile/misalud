<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Concerns\TieneAdjuntos;
use App\Contracts\CifraDatos;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\RolPaciente;
use App\Observers\PacienteObserver;
use App\Policies\PacientePolicy;
use Carbon\CarbonImmutable;
use Database\Factories\PacienteFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A quien pertenece la historia clínica: el usuario mismo, o un familiar que
 * administra. `usuario_id` es quien lo dio de alta; el acceso real —de él y
 * de quien más lo comparta— vive siempre en el pivote `paciente_usuario`,
 * nunca se compara `usuario_id` a mano en ninguna Policy del dominio clínico.
 *
 * @property int $id
 * @property int $usuario_id
 * @property string $nombre
 * @property CarbonImmutable|null $fecha_nacimiento
 * @property string|null $sexo
 * @property string|null $grupo_sanguineo
 * @property string|null $notas
 * @property-read int|null $edad
 */
#[ObservedBy(PacienteObserver::class)]
#[UsePolicy(PacientePolicy::class)]
class Paciente extends Model implements CifraDatos
{
    use CifraCampos;

    /** @use HasFactory<PacienteFactory> */
    use HasFactory;

    use SoftDeletes;
    use TieneAdjuntos;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'pacientes';

    /*
     * A diferencia de un registro clínico colgado de un paciente (donde
     * paciente_id NUNCA es fillable, ver CLAUDE.md), acá `usuario_id` sí lo es:
     * este es el registro raíz, y quien lo crea es siempre el usuario
     * autenticado -no hay una relación intermedia de la que colgarlo-.
     */
    protected $fillable = [
        'usuario_id',
        'nombre',
        'fecha_nacimiento',
        'sexo',
        'grupo_sanguineo',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'nombre' => 'encrypted',
            'grupo_sanguineo' => 'encrypted',
            'notas' => 'encrypted',
        ];
    }

    /**
     * Quien dio de alta al paciente. No es "el dueño de los permisos" —eso lo
     * decide el pivote—, es solo de quién fue el alta original.
     *
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * Todos los usuarios con acceso, con su rol en el pivote.
     * La autorización pasa SIEMPRE por acá.
     *
     * @return BelongsToMany<User, $this>
     */
    public function cuidadores(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'paciente_usuario', 'paciente_id', 'usuario_id')
            ->withPivot('rol')
            ->withTimestamps();
    }

    public function rolDe(User $usuario): ?RolPaciente
    {
        $fila = $this->cuidadores->firstWhere('id', $usuario->id);
        $rol = $fila?->getRelationValue('pivot')?->getAttribute('rol');

        return is_string($rol) ? RolPaciente::from($rol) : null;
    }

    /**
     * La edad NO se guarda: se deriva de `fecha_nacimiento` en cada request.
     * Guardarla como dato dejaría desactualizada a toda ficha al día
     * siguiente de cargada.
     */
    /**
     * @return Attribute<int|null, never>
     */
    protected function edad(): Attribute
    {
        return Attribute::make(
            get: fn (): ?int => $this->fecha_nacimiento?->age,
        );
    }
}
