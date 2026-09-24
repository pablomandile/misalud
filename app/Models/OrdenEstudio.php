<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Concerns\TieneAdjuntos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Contracts\TieneArchivos;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\EstadoOrdenEstudio;
use App\Policies\RegistroClinicoPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\OrdenEstudioFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * El papel que da el médico antes de un estudio.
 *
 * Es el **primer registro clínico que tiene archivos propios**: hasta acá
 * los adjuntos colgaban del paciente, de una cobertura o de un catálogo
 * (ver `TieneArchivos`). El archivo de una orden es la orden misma
 * fotografiada, que es lo que hay que mostrar en el mostrador del
 * laboratorio.
 *
 * @property int $id
 * @property int $paciente_id
 * @property int|null $medico_id
 * @property string $estudio_solicitado
 * @property CarbonImmutable $fecha
 * @property EstadoOrdenEstudio $estado
 * @property string|null $notas
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class OrdenEstudio extends Model implements CifraDatos, PerteneceAPaciente, TieneArchivos
{
    use CifraCampos;

    /** @use HasFactory<OrdenEstudioFactory> */
    use HasFactory;

    use SoftDeletes;
    use TieneAdjuntos;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'ordenes_estudio';

    /*
     * `paciente_id` NO es fillable: es la FK de la que depende toda la
     * autorización. Se crea por la relación del paciente.
     */
    protected $fillable = [
        'medico_id',
        'estudio_solicitado',
        'fecha',
        'estado',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estudio_solicitado' => 'encrypted',
            'notas' => 'encrypted',
            'fecha' => 'date',
            'estado' => EstadoOrdenEstudio::class,
        ];
    }

    public function indicesCiegos(): array
    {
        /*
         * Ninguno: dos órdenes del mismo estudio son normales -un control
         * que se repite cada seis meses son dos órdenes distintas-, así que
         * un UNIQUE acá sería un estorbo y no una protección.
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
     * Quién la indicó. Opcional, y `withTrashed()` porque borrar un médico
     * del catálogo es un soft delete: sin esto la orden aparecería de golpe
     * sin médico, sin que nadie la haya tocado.
     *
     * @return BelongsTo<Medico, $this>
     */
    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class)->withTrashed();
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->paciente;
    }
}
