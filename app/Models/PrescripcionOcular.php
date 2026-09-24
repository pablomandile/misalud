<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Concerns\TieneAdjuntos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Contracts\TieneArchivos;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\Ojo;
use App\Enums\TipoPrescripcionOcular;
use App\Policies\RegistroClinicoPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\PrescripcionOcularFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una receta de anteojos. Los números viven en sus dos `graduaciones`, una
 * por ojo.
 *
 * **Se guarda tal cual figura en el papel.** Nada se normaliza al entrar: ni
 * el signo del cilindro -que cada óptica escribe a su manera y que la
 * transposición del paso 10.3 muestra de la otra forma sin tocar lo
 * guardado-, ni el redondeo. Que lo que está en pantalla coincida con lo que
 * la persona tiene en la mano es lo que hace verificable a toda la sección.
 *
 * @property int $id
 * @property int $paciente_id
 * @property int|null $medico_id
 * @property int|null $centro_id
 * @property TipoPrescripcionOcular $tipo
 * @property CarbonImmutable $fecha
 * @property string|null $dp_total
 * @property string|null $notas
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class PrescripcionOcular extends Model implements CifraDatos, PerteneceAPaciente, TieneArchivos
{
    use CifraCampos;

    /** @use HasFactory<PrescripcionOcularFactory> */
    use HasFactory;

    use SoftDeletes;
    use TieneAdjuntos;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'prescripciones_oculares';

    /*
     * `paciente_id` NO es fillable: es la FK de la que depende toda la
     * autorización. Se crea por la relación del paciente.
     */
    protected $fillable = [
        'medico_id',
        'centro_id',
        'tipo',
        'fecha',
        'dp_total',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoPrescripcionOcular::class,
            'fecha' => 'date',
            'dp_total' => 'encrypted',
            'notas' => 'encrypted',
        ];
    }

    public function indicesCiegos(): array
    {
        /*
         * Ninguno. Dos recetas del mismo tipo y la misma fecha son posibles
         * -un control repetido, una segunda opinión-, así que no hay nada
         * que proteger con un UNIQUE. El único UNIQUE de esta etapa vive en
         * `graduaciones_oculares` y es sobre columnas en claro.
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
     * Quién la hizo. `withTrashed()`: borrar un médico del catálogo es un
     * soft delete, y sin esto la receta aparecería de golpe sin médico.
     *
     * @return BelongsTo<Medico, $this>
     */
    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class)->withTrashed();
    }

    /**
     * Dónde: la óptica o el consultorio. `withTrashed()` por lo mismo.
     *
     * @return BelongsTo<Centro, $this>
     */
    public function centro(): BelongsTo
    {
        return $this->belongsTo(Centro::class)->withTrashed();
    }

    /**
     * Las dos: siempre OD y OI, aunque una esté toda en `null`.
     *
     * @return HasMany<GraduacionOcular, $this>
     */
    public function graduaciones(): HasMany
    {
        return $this->hasMany(GraduacionOcular::class, 'prescripcion_id');
    }

    /**
     * La graduación de un ojo, **sobre la relación ya cargada**.
     *
     * Sin esto, una pantalla que muestra diez recetas dispararía veinte
     * consultas -dos por receta- aunque hubiera hecho el eager loading.
     */
    public function graduacionDe(Ojo $ojo): ?GraduacionOcular
    {
        return $this->graduaciones->firstWhere('ojo', $ojo);
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->paciente;
    }
}
