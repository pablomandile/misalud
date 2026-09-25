<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\EstadoTurno;
use App\Observers\TurnoObserver;
use App\Policies\RegistroClinicoPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\TurnoFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una visita agendada.
 *
 * `#[ObservedBy]` no es decoración: es lo que mantiene su recordatorio al
 * día. Todo lo que pase con un turno —se crea, se mueve de hora, se
 * cancela, se borra— tiene que reflejarse en el aviso, y el único lugar
 * donde eso se decide es `TurnoObserver`.
 *
 * @property int $id
 * @property int $paciente_id
 * @property int|null $medico_id
 * @property int|null $centro_id
 * @property int|null $orden_estudio_id
 * @property CarbonImmutable $fecha_hora
 * @property string|null $motivo
 * @property EstadoTurno $estado
 */
#[ObservedBy(TurnoObserver::class)]
#[UsePolicy(RegistroClinicoPolicy::class)]
class Turno extends Model implements CifraDatos, PerteneceAPaciente
{
    use CifraCampos;

    /** @use HasFactory<TurnoFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'turnos';

    /*
     * `paciente_id` NO es fillable: es la FK de la que depende toda la
     * autorización. Se crea por la relación del paciente.
     */
    protected $fillable = [
        'medico_id',
        'centro_id',
        'orden_estudio_id',
        'fecha_hora',
        'motivo',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_hora' => 'datetime',
            'motivo' => 'encrypted',
            'estado' => EstadoTurno::class,
        ];
    }

    public function indicesCiegos(): array
    {
        /*
         * Ninguno. Dos turnos con el mismo motivo son normales -un control
         * que se repite cada seis meses-, así que no hay unicidad que
         * proteger ni búsqueda por texto que hacer.
         */
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
     * Con quién. `withTrashed()`: borrar un médico del catálogo es un soft
     * delete, y sin esto el turno aparecería de golpe sin médico.
     *
     * @return BelongsTo<Medico, $this>
     */
    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class)->withTrashed();
    }

    /**
     * Dónde. `withTrashed()` por el mismo motivo.
     *
     * @return BelongsTo<Centro, $this>
     */
    public function centro(): BelongsTo
    {
        return $this->belongsTo(Centro::class)->withTrashed();
    }

    /**
     * La orden que este turno viene a resolver, si la hay. Es el eslabón
     * que faltaba entre la orden (Etapa 9.1) y el estudio (9.2).
     *
     * @return BelongsTo<OrdenEstudio, $this>
     */
    public function ordenEstudio(): BelongsTo
    {
        return $this->belongsTo(OrdenEstudio::class);
    }

    /**
     * Sus recordatorios. En la práctica uno solo por ahora, pero la relación
     * es `morphMany` porque la clave de idempotencia incluye el `tipo`: nada
     * impide que un turno llegue a tener dos avisos con distinta
     * anticipación.
     *
     * @return MorphMany<Recordatorio, $this>
     */
    public function recordatorios(): MorphMany
    {
        return $this->morphMany(Recordatorio::class, 'origen');
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->paciente;
    }
}
