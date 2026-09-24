<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\SeveridadAlergia;
use App\Policies\RegistroClinicoPolicy;
use Database\Factories\AlergiaFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A qué es alérgico un paciente.
 *
 * @property int $id
 * @property int $paciente_id
 * @property string $sustancia
 * @property string|null $reaccion
 * @property SeveridadAlergia $severidad
 * @property string|null $notas
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class Alergia extends Model implements CifraDatos, PerteneceAPaciente
{
    use CifraCampos;

    /** @use HasFactory<AlergiaFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'alergias';

    protected $fillable = [
        'sustancia',
        'reaccion',
        'severidad',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sustancia' => 'encrypted',
            'reaccion' => 'encrypted',
            'notas' => 'encrypted',
            'severidad' => SeveridadAlergia::class,
        ];
    }

    public function indicesCiegos(): array
    {
        return ['sustancia' => 'sustancia_hash'];
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
