<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Concerns\TieneAdjuntos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Contracts\TieneArchivos;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\PeriodicidadToma;
use App\Observers\TratamientoObserver;
use App\Policies\RegistroClinicoPolicy;
use App\Support\Enumeracion;
use Carbon\CarbonImmutable;
use Database\Factories\TratamientoFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string|null $frecuencia
 * @property PeriodicidadToma|null $periodicidad
 * @property list<int>|null $dias_semana
 * @property int|null $tomas_por_dia
 * @property list<string>|null $horas
 * @property int|null $aplicacion_dia
 * @property int|null $aplicacion_mes
 * @property CarbonImmutable $inicio
 * @property CarbonImmutable|null $fin
 * @property bool $activo
 * @property string|null $notas
 * @property string|null $plantilla_pedido
 */
#[ObservedBy(TratamientoObserver::class)]
#[UsePolicy(RegistroClinicoPolicy::class)]
class Tratamiento extends Model implements CifraDatos, PerteneceAPaciente, TieneArchivos
{
    use CifraCampos;

    /** @use HasFactory<TratamientoFactory> */
    use HasFactory;

    use SoftDeletes;

    /*
     * Las fotos de la caja: undécimo dueño de archivos. La autorización no
     * cambia: subir una foto es editar el tratamiento (ver AdjuntoPolicy).
     */
    use TieneAdjuntos;

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
        'periodicidad',
        'dias_semana',
        'tomas_por_dia',
        'horas',
        'aplicacion_dia',
        'aplicacion_mes',
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
            'periodicidad' => PeriodicidadToma::class,
            'dias_semana' => 'array',
            'horas' => 'array',
            'aplicacion_dia' => 'integer',
            'aplicacion_mes' => 'integer',
            'notas' => 'encrypted',
            // El mensaje del último pedido de este medicamento. No es fillable: lo escribe el pedido.
            'plantilla_pedido' => 'encrypted',
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

    /**
     * Cada aplicación o toma anotada en la sección «Tratamiento».
     *
     * @return HasMany<AplicacionMedicamento, $this>
     */
    public function aplicaciones(): HasMany
    {
        return $this->hasMany(AplicacionMedicamento::class);
    }

    /**
     * Cómo se lee la toma: la etiqueta de la periodicidad, o los días cuando
     * es "algunos días de la semana".
     */
    public function tomaVisible(): ?string
    {
        if ($this->periodicidad === PeriodicidadToma::Personalizada && ! empty($this->dias_semana)) {
            return PeriodicidadToma::nombrarDias($this->dias_semana);
        }

        return $this->periodicidad?->etiqueta();
    }

    /**
     * "3 veces por día". Una sola no se dice: "todos los días" ya la nombra.
     */
    public function tomasVisible(): ?string
    {
        return $this->tomas_por_dia !== null && $this->tomas_por_dia > 1
            ? "{$this->tomas_por_dia} veces por día"
            : null;
    }

    /**
     * "a las 08:00, 14:00 y 20:00".
     */
    public function horarioVisible(): ?string
    {
        return empty($this->horas) ? null : 'a las '.Enumeracion::unir($this->horas);
    }

    /**
     * La próxima vez que toca la aplicación de "una vez por año": el día y el
     * mes cargados, este año si todavía no pasó (hoy cuenta) y si no el que
     * viene. Un 29 de febrero cae el 28 en los años que no lo tienen.
     */
    public function proximaAplicacion(CarbonImmutable $hoy): ?CarbonImmutable
    {
        if ($this->periodicidad !== PeriodicidadToma::Anual
            || $this->aplicacion_dia === null
            || $this->aplicacion_mes === null) {
            return null;
        }

        $hoy = $hoy->startOfDay();
        $fecha = $this->aplicacionEn($hoy->year);

        return $fecha->lessThan($hoy) ? $this->aplicacionEn($hoy->year + 1) : $fecha;
    }

    /**
     * "Cada año el 15 de marzo".
     */
    public function aplicacionVisible(): ?string
    {
        if ($this->aplicacion_dia === null || $this->aplicacion_mes === null) {
            return null;
        }

        $mes = CarbonImmutable::create(2000, $this->aplicacion_mes, 1)?->translatedFormat('F');

        return "Cada año el {$this->aplicacion_dia} de {$mes}";
    }

    private function aplicacionEn(int $anio): CarbonImmutable
    {
        $primero = CarbonImmutable::create($anio, (int) $this->aplicacion_mes, 1, 0, 0, 0, 'UTC');
        assert($primero instanceof CarbonImmutable);

        return $primero->setDay(min((int) $this->aplicacion_dia, $primero->daysInMonth));
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->paciente;
    }
}
