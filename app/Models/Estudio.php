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
use Database\Factories\EstudioFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un estudio ya hecho: el resultado de después de la orden.
 *
 * @property int $id
 * @property int $paciente_id
 * @property int|null $medico_id
 * @property int|null $centro_id
 * @property int|null $enfermedad_id
 * @property string $tipo
 * @property CarbonImmutable $fecha
 * @property string|null $notas
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class Estudio extends Model implements CifraDatos, PerteneceAPaciente, TieneArchivos
{
    use CifraCampos;

    /** @use HasFactory<EstudioFactory> */
    use HasFactory;

    use SoftDeletes;
    use TieneAdjuntos;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'estudios';

    /*
     * `paciente_id` NO es fillable: es la FK de la que depende toda la
     * autorización. Se crea por la relación del paciente.
     */
    protected $fillable = [
        'medico_id',
        'centro_id',
        'enfermedad_id',
        'tipo',
        'fecha',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => 'encrypted',
            'notas' => 'encrypted',
            'fecha' => 'date',
        ];
    }

    public function indicesCiegos(): array
    {
        // Ninguno: dos estudios del mismo tipo son normales -un control que
        // se repite cada año son dos estudios distintos-.
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
     * Quién lo pidió. `withTrashed()`: borrar un médico del catálogo es un
     * soft delete, y sin esto el estudio aparecería de golpe sin médico.
     *
     * @return BelongsTo<Medico, $this>
     */
    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class)->withTrashed();
    }

    /**
     * Dónde se hizo. `withTrashed()` por el mismo motivo que `medico()`.
     *
     * @return BelongsTo<Centro, $this>
     */
    public function centro(): BelongsTo
    {
        return $this->belongsTo(Centro::class)->withTrashed();
    }

    /**
     * Por qué. Opcional: no todo estudio tiene una enfermedad cargada -un
     * chequeo de rutina no es por nada en particular-.
     *
     * @return BelongsTo<Enfermedad, $this>
     */
    public function enfermedad(): BelongsTo
    {
        return $this->belongsTo(Enfermedad::class);
    }

    /**
     * La orden que este estudio resuelve, si la hay.
     *
     * Es el lado "uno" de una relación que en realidad vive del otro lado
     * -`OrdenEstudio.estudio_id`-, así que acá es un `HasMany` que en la
     * práctica nunca tiene más de una fila: nada impide a nivel de base que
     * dos órdenes apunten al mismo estudio, pero la pantalla solo ofrece
     * vincular una a la vez.
     *
     * @return HasMany<OrdenEstudio, $this>
     */
    public function ordenes(): HasMany
    {
        return $this->hasMany(OrdenEstudio::class);
    }

    /**
     * Los parámetros del estudio (Etapa 9.3): "Glucemia: 90 mg/dl".
     *
     * @return HasMany<ResultadoEstudio, $this>
     */
    public function resultados(): HasMany
    {
        return $this->hasMany(ResultadoEstudio::class);
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->paciente;
    }
}
