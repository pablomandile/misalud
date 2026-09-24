<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Database\Eloquent\ConsultaVigilada;
use App\Policies\RegistroClinicoPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\MedicionFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una toma: un peso, una presión, una glucemia.
 *
 * ⚠️ **`valor` y `valor_secundario` vuelven como STRING, no como número.**
 * Están cifrados, y una columna cifrada es `text`: Laravel no tiene un cast
 * `encrypted:float`, así que lo que sale del modelo es el texto tal cual se
 * guardó. Eso convierte en trampa cualquier cuenta o comparación directa:
 *
 *     $mediciones->sortBy('valor')          // ordena como texto: "100" < "9"
 *     $mediciones->max('valor')             // el máximo alfabético
 *     $a->valor > $b->valor                 // comparación de strings
 *
 * Ninguna de las tres falla: devuelven algo, y está mal. Por eso el valor
 * numérico se pide SIEMPRE por `valorNumerico()` / `valorSecundarioNumerico()`,
 * que son los que usan el listado y el gráfico.
 *
 * @property int $id
 * @property int $paciente_id
 * @property int $tipo_medicion_id
 * @property CarbonImmutable $fecha
 * @property string $valor
 * @property string|null $valor_secundario
 * @property string|null $notas
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class Medicion extends Model implements CifraDatos, PerteneceAPaciente
{
    use CifraCampos;

    /** @use HasFactory<MedicionFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'mediciones';

    /*
     * `paciente_id` NO es fillable: es la FK de la que depende toda la
     * autorización. Se crea por la relación,
     * `$paciente->mediciones()->create(...)`.
     */
    protected $fillable = [
        'tipo_medicion_id',
        /*
         * Opcional: la enfermedad que se está siguiendo con esta medición.
         * SÍ es fillable -a diferencia de `paciente_id`- porque no decide
         * ninguna autorización: es un vínculo entre dos registros del MISMO
         * paciente, y que sean del mismo lo valida el FormRequest.
         */
        'enfermedad_id',
        'fecha',
        'valor',
        'valor_secundario',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'valor' => 'encrypted',
            'valor_secundario' => 'encrypted',
            'notas' => 'encrypted',
        ];
    }

    public function indicesCiegos(): array
    {
        // Ninguno: no hay unicidad ni búsqueda exacta por valor. Dos tomas
        // del mismo número el mismo día son perfectamente normales —mañana
        // y noche—, así que un UNIQUE acá sería un error, no una protección.
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
     * El tipo, **incluso si está en la papelera**.
     *
     * Un tipo con mediciones cargadas no se puede borrar -lo frena
     * `TipoMedicionController::destroy`-, pero sí se puede borrar uno cuyas
     * mediciones estén todas en la papelera. Si alguna se restaura después,
     * sin `withTrashed` esta relación devolvería `null` y la fila aparecería
     * en pantalla sin nombre ni unidad: un número suelto, que es peor que no
     * mostrarlo.
     *
     * @return BelongsTo<TipoMedicion, $this>
     */
    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoMedicion::class, 'tipo_medicion_id')->withTrashed();
    }

    /**
     * La enfermedad que se sigue con esta medición, si hay alguna.
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

    public function valorNumerico(): float
    {
        return (float) $this->valor;
    }

    public function valorSecundarioNumerico(): ?float
    {
        return $this->valor_secundario === null ? null : (float) $this->valor_secundario;
    }
}
