<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Database\Eloquent\ConsultaVigilada;
use App\Policies\RegistroClinicoPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\AplicacionMedicamentoFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una aplicación o toma de un medicamento que no es diario: una línea de la
 * sección «Tratamiento», con la enfermedad por la que se hizo.
 *
 * @property int $id
 * @property int $paciente_id
 * @property int $tratamiento_id
 * @property int|null $enfermedad_id
 * @property CarbonImmutable $fecha
 * @property string|null $notas
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class AplicacionMedicamento extends Model implements CifraDatos, PerteneceAPaciente
{
    use CifraCampos;

    /** @use HasFactory<AplicacionMedicamentoFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'aplicaciones_medicamento';

    /*
     * `paciente_id` NO es fillable: es la FK de la que depende toda la
     * autorización. Se crea por la relación del paciente.
     */
    protected $fillable = [
        'tratamiento_id',
        'enfermedad_id',
        'fecha',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'notas' => 'encrypted',
        ];
    }

    public function indicesCiegos(): array
    {
        // Ninguno: el mismo medicamento se aplica muchas veces.
        return [];
    }

    /**
     * @return BelongsTo<Paciente, $this>
     */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    /**
     * @return BelongsTo<Tratamiento, $this>
     */
    public function tratamiento(): BelongsTo
    {
        return $this->belongsTo(Tratamiento::class)->withTrashed();
    }

    /**
     * `withTrashed()`: una enfermedad en la papelera se puede restaurar, y la
     * aplicación no tiene por qué perder el grupo mientras tanto.
     *
     * @return BelongsTo<Enfermedad, $this>
     */
    public function enfermedad(): BelongsTo
    {
        return $this->belongsTo(Enfermedad::class)->withTrashed();
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->paciente;
    }
}
