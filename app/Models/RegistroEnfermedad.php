<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Database\Eloquent\ConsultaVigilada;
use App\Policies\RegistroClinicoPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\RegistroEnfermedadFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una anotación en la bitácora de una enfermedad.
 *
 * **Es el primer registro clínico que llega a su paciente en DOS pasos**:
 * sube por `enfermedad` y recién ahí encuentra al paciente. Por eso el
 * contrato `PerteneceAPaciente` pide un método y no una relación — ver su
 * comentario—: con una relación `BelongsTo` obligatoria, esto no podría
 * cumplirlo.
 *
 * @property int $id
 * @property int $enfermedad_id
 * @property CarbonImmutable $fecha
 * @property string $nota
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class RegistroEnfermedad extends Model implements CifraDatos, PerteneceAPaciente
{
    use CifraCampos;

    /** @use HasFactory<RegistroEnfermedadFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'registros_enfermedad';

    /*
     * `enfermedad_id` NO es fillable, por lo mismo que `paciente_id` en el
     * resto del dominio: de esa referencia cuelga la autorización entera.
     * Se crea por la relación, `$enfermedad->registros()->create(...)`.
     */
    protected $fillable = [
        'fecha',
        'nota',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nota' => 'encrypted',
            'fecha' => 'date',
        ];
    }

    public function indicesCiegos(): array
    {
        return [];
    }

    /**
     * @return BelongsTo<Enfermedad, $this>
     */
    public function enfermedad(): BelongsTo
    {
        return $this->belongsTo(Enfermedad::class);
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->enfermedad?->pacienteDelRegistro();
    }
}
