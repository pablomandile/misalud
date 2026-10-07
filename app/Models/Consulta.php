<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Concerns\TieneAdjuntos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Contracts\TieneArchivos;
use App\Database\Eloquent\ConsultaVigilada;
use App\Policies\RegistroClinicoPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\ConsultaFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * La visita al médico, y lo que se dijo.
 *
 * Noveno dueño de archivos: la grabación de la consulta es un adjunto
 * `audio_consulta`, el único tipo que se guarda SIN cifrar (ver
 * `TipoAdjunto::seGuardaCifrado()`).
 *
 * @property int $id
 * @property int $paciente_id
 * @property int|null $medico_id
 * @property int|null $centro_id
 * @property int|null $enfermedad_id
 * @property int|null $turno_id
 * @property CarbonImmutable $fecha_hora
 * @property string|null $motivo
 * @property string|null $notas
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class Consulta extends Model implements CifraDatos, PerteneceAPaciente, TieneArchivos
{
    use CifraCampos;

    /** @use HasFactory<ConsultaFactory> */
    use HasFactory;

    use SoftDeletes;
    use TieneAdjuntos;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'consultas';

    /*
     * `paciente_id` NO es fillable: es la FK de la que depende toda la
     * autorización. Se crea por la relación del paciente.
     */
    protected $fillable = [
        'medico_id',
        'centro_id',
        'enfermedad_id',
        'turno_id',
        'fecha_hora',
        'motivo',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'motivo' => 'encrypted',
            'notas' => 'encrypted',
            'fecha_hora' => 'datetime',
        ];
    }

    public function indicesCiegos(): array
    {
        // Ninguno: nada se busca ni se deduplica por el contenido de una consulta.
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
     * `withTrashed()`: el médico se pudo haber borrado con un soft delete.
     *
     * @return BelongsTo<Medico, $this>
     */
    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Centro, $this>
     */
    public function centro(): BelongsTo
    {
        return $this->belongsTo(Centro::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Enfermedad, $this>
     */
    public function enfermedad(): BelongsTo
    {
        return $this->belongsTo(Enfermedad::class);
    }

    /**
     * @return BelongsTo<Turno, $this>
     */
    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->paciente;
    }
}
