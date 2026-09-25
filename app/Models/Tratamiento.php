<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Database\Eloquent\ConsultaVigilada;
use App\Observers\TratamientoObserver;
use App\Policies\RegistroClinicoPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\TratamientoFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Qué medicamento toma un paciente, con qué dosis y por qué.
 *
 * @property int $id
 * @property int $paciente_id
 * @property int $medicamento_id
 * @property int|null $medico_id
 * @property int|null $enfermedad_id
 * @property string $dosis
 * @property string $frecuencia
 * @property CarbonImmutable $inicio
 * @property CarbonImmutable|null $fin
 * @property bool $activo
 * @property string|null $notas
 */
#[ObservedBy(TratamientoObserver::class)]
#[UsePolicy(RegistroClinicoPolicy::class)]
class Tratamiento extends Model implements CifraDatos, PerteneceAPaciente
{
    use CifraCampos;

    /** @use HasFactory<TratamientoFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'tratamientos';

    /*
     * `paciente_id` NO es fillable: es la FK de la que depende toda la
     * autorización. Se crea por la relación del paciente.
     */
    protected $fillable = [
        'medicamento_id',
        'medico_id',
        'enfermedad_id',
        'dosis',
        'frecuencia',
        'inicio',
        'fin',
        'activo',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dosis' => 'encrypted',
            'frecuencia' => 'encrypted',
            'notas' => 'encrypted',
            'inicio' => 'date',
            'fin' => 'date',
            'activo' => 'boolean',
        ];
    }

    public function indicesCiegos(): array
    {
        /*
         * Ninguno: dos tratamientos con la misma dosis y frecuencia son
         * normales -un mismo medicamento suspendido y retomado meses
         * después-, así que un UNIQUE acá sería un estorbo.
         */
        return [];
    }

    /**
     * Sus recordatorios -el aviso de que está por terminar-. Los mantiene
     * `TratamientoObserver`, nunca un formulario.
     *
     * @return MorphMany<Recordatorio, $this>
     */
    public function recordatorios(): MorphMany
    {
        return $this->morphMany(Recordatorio::class, 'origen');
    }

    /**
     * @return BelongsTo<Paciente, $this>
     */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    /**
     * El medicamento. `withTrashed()`: un catálogo se borra con un soft
     * delete, y sin esto un tratamiento vigente aparecería de golpe sin
     * saber qué medicamento es —aunque la fila siga ahí—.
     *
     * @return BelongsTo<Medicamento, $this>
     */
    public function medicamento(): BelongsTo
    {
        return $this->belongsTo(Medicamento::class)->withTrashed();
    }

    /**
     * Quién lo indicó. Opcional, y `withTrashed()` por el mismo motivo.
     *
     * @return BelongsTo<Medico, $this>
     */
    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class)->withTrashed();
    }

    /**
     * Por qué. Opcional: no todo tratamiento tiene una enfermedad cargada
     * -un suplemento, una vitamina-.
     *
     * @return BelongsTo<Enfermedad, $this>
     */
    public function enfermedad(): BelongsTo
    {
        return $this->belongsTo(Enfermedad::class);
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->paciente;
    }
}
