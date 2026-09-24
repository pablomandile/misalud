<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\EstadoEnfermedad;
use App\Policies\RegistroClinicoPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\EnfermedadFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lo que tiene o tuvo un paciente.
 *
 * @property int $id
 * @property int $paciente_id
 * @property int|null $medico_id
 * @property string $nombre
 * @property CarbonImmutable|null $fecha_diagnostico
 * @property EstadoEnfermedad $estado
 * @property string|null $notas
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class Enfermedad extends Model implements CifraDatos, PerteneceAPaciente
{
    use CifraCampos;

    /** @use HasFactory<EnfermedadFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'enfermedades';

    /*
     * `paciente_id` NO es fillable: es la FK de la que depende toda la
     * autorización. Se crea por la relación del paciente.
     */
    protected $fillable = [
        'medico_id',
        'nombre',
        'fecha_diagnostico',
        'estado',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nombre' => 'encrypted',
            'notas' => 'encrypted',
            'fecha_diagnostico' => 'date',
            'estado' => EstadoEnfermedad::class,
        ];
    }

    public function indicesCiegos(): array
    {
        /*
         * Ninguno, y a diferencia de las alergias es deliberado: dos
         * entradas con el mismo nombre son normales -dos neumonías en años
         * distintos son dos enfermedades, no una cargada dos veces-, así
         * que un UNIQUE acá sería un estorbo y no una protección.
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
     * Quién la diagnosticó. Opcional.
     *
     * `withTrashed()` por lo mismo que `Medicion::tipo()`: borrar un médico
     * del catálogo es un soft delete, la fila sigue ahí y sin esto la
     * relación devolvería `null` —la enfermedad aparecería de golpe sin
     * médico, sin que nadie haya tocado la enfermedad—.
     *
     * @return BelongsTo<Medico, $this>
     */
    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class)->withTrashed();
    }

    /**
     * La bitácora: qué fue pasando, en texto.
     *
     * @return HasMany<RegistroEnfermedad, $this>
     */
    public function registros(): HasMany
    {
        return $this->hasMany(RegistroEnfermedad::class);
    }

    /**
     * Las mediciones que se están siguiendo por esta enfermedad.
     *
     * Los números NO viven en la bitácora: viven en `mediciones` y apuntan
     * acá. Así la curva es una sola, se haya cargado desde donde se haya
     * cargado (ver la migración de `registros_enfermedad`).
     *
     * @return HasMany<Medicion, $this>
     */
    public function mediciones(): HasMany
    {
        return $this->hasMany(Medicion::class);
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->paciente;
    }
}
