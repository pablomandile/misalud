<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Concerns\TieneAdjuntos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Contracts\TieneArchivos;
use App\Database\Eloquent\ConsultaVigilada;
use App\Observers\AplicacionVacunaObserver;
use App\Policies\RegistroClinicoPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\AplicacionVacunaFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una dosis de vacuna aplicada: una línea del carnet.
 *
 * Es el **octavo dueño de archivos** —el comprobante o la foto del carnet—, y
 * declararlo no tocó nada de autorización, igual que los siete anteriores.
 *
 * @property int $id
 * @property int $paciente_id
 * @property int $vacuna_id
 * @property int|null $centro_id
 * @property CarbonImmutable $fecha
 * @property CarbonImmutable|null $proxima_dosis
 * @property string|null $dosis
 * @property string|null $lote
 * @property string|null $notas
 */
#[ObservedBy(AplicacionVacunaObserver::class)]
#[UsePolicy(RegistroClinicoPolicy::class)]
class AplicacionVacuna extends Model implements CifraDatos, PerteneceAPaciente, TieneArchivos
{
    use CifraCampos;

    /** @use HasFactory<AplicacionVacunaFactory> */
    use HasFactory;

    use SoftDeletes;
    use TieneAdjuntos;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'aplicaciones_vacuna';

    /*
     * `paciente_id` NO es fillable: es la FK de la que depende toda la
     * autorización. Se crea por la relación del paciente.
     */
    protected $fillable = [
        'vacuna_id',
        'centro_id',
        'fecha',
        'proxima_dosis',
        'dosis',
        'lote',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dosis' => 'encrypted',
            'lote' => 'encrypted',
            'notas' => 'encrypted',
            'fecha' => 'date',
            'proxima_dosis' => 'date',
        ];
    }

    public function indicesCiegos(): array
    {
        /*
         * Ninguno: la misma vacuna se repite —la antigripal, todos los años— y
         * dos dosis el mismo día con el mismo lote no son un error que haya
         * que atajar con un UNIQUE.
         */
        return [];
    }

    /**
     * El aviso de la próxima dosis. Lo mantiene `AplicacionVacunaObserver`.
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
     * `withTrashed()`: el borrado normal de un catálogo es un soft delete, y sin
     * esto la dosis aparecería de golpe sin saber de qué vacuna es.
     *
     * @return BelongsTo<Vacuna, $this>
     */
    public function vacuna(): BelongsTo
    {
        return $this->belongsTo(Vacuna::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Centro, $this>
     */
    public function centro(): BelongsTo
    {
        return $this->belongsTo(Centro::class)->withTrashed();
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->paciente;
    }
}
