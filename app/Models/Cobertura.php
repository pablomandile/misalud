<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Concerns\TieneAdjuntos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\TipoCobertura;
use App\Policies\RegistroClinicoPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\CoberturaFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * La cobertura médica de un paciente: obra social, prepaga, mutual o PAMI.
 *
 * Primer modelo del dominio clínico que combina cifrado (`CifraDatos`) con
 * pertenencia a un paciente (`PerteneceAPaciente`): las dos piezas conviven
 * sin fricción porque resuelven cosas distintas — una protege el contenido,
 * la otra resuelve la autorización.
 *
 * @property int $id
 * @property int $paciente_id
 * @property TipoCobertura $tipo
 * @property string $entidad
 * @property string|null $plan
 * @property string|null $nro_afiliado
 * @property string|null $telefono
 * @property string|null $telefono_urgencias
 * @property string|null $sitio_web
 * @property CarbonImmutable|null $vigencia_desde
 * @property CarbonImmutable|null $vigencia_hasta
 * @property bool $activa
 * @property string|null $notas
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class Cobertura extends Model implements CifraDatos, PerteneceAPaciente
{
    use CifraCampos;

    /** @use HasFactory<CoberturaFactory> */
    use HasFactory;

    use SoftDeletes;
    use TieneAdjuntos;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'coberturas';

    /*
     * `paciente_id` NO es fillable: es la FK de la que depende toda la
     * autorización. Se crea por la relación,
     * `$paciente->coberturas()->create(...)`.
     */
    protected $fillable = [
        'tipo',
        'entidad',
        'plan',
        'nro_afiliado',
        'telefono',
        'telefono_urgencias',
        'sitio_web',
        'vigencia_desde',
        'vigencia_hasta',
        'activa',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoCobertura::class,
            'entidad' => 'encrypted',
            'plan' => 'encrypted',
            'nro_afiliado' => 'encrypted',
            'telefono' => 'encrypted',
            'telefono_urgencias' => 'encrypted',
            'sitio_web' => 'encrypted',
            'notas' => 'encrypted',
            'vigencia_desde' => 'date',
            'vigencia_hasta' => 'date',
            'activa' => 'boolean',
        ];
    }

    public function indicesCiegos(): array
    {
        return ['entidad' => 'entidad_hash'];
    }

    /**
     * @return BelongsTo<Paciente, $this>
     */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->paciente;
    }
}
