<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Concerns\TieneAdjuntos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Contracts\TieneArchivos;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\EstadoReceta;
use App\Policies\RegistroClinicoPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\RecetaMedicaFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una receta de medicamentos en la ficha del paciente: el papel (o el PDF) que
 * firma el médico para comprar en la farmacia.
 *
 * ⚠️ No confundir con `Receta`, la bandeja de lo que llega por mail: esa cuelga
 * del usuario porque el mail no dice de quién es; esta la carga alguien sabiendo
 * para quién es. Noveno dueño de archivos.
 *
 * @property int $id
 * @property int $paciente_id
 * @property int|null $medico_id
 * @property int|null $medicamento_id
 * @property string|null $detalle
 * @property CarbonImmutable $fecha
 * @property int $vigencia_dias
 * @property EstadoReceta $estado
 * @property string|null $notas
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class RecetaMedica extends Model implements CifraDatos, PerteneceAPaciente, TieneArchivos
{
    use CifraCampos;

    /** @use HasFactory<RecetaMedicaFactory> */
    use HasFactory;

    use SoftDeletes;
    use TieneAdjuntos;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'recetas_medicas';

    /*
     * `paciente_id` NO es fillable: se crea por la relación. `estado` tampoco:
     * lo mueve solo la acción de marcarla usada, que es su propia ruta.
     */
    protected $fillable = [
        'medico_id',
        'medicamento_id',
        'detalle',
        'fecha',
        'vigencia_dias',
        'notas',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => 'disponible',
        'vigencia_dias' => 30,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'detalle' => 'encrypted',
            'notas' => 'encrypted',
            'fecha' => 'date',
            'vigencia_dias' => 'integer',
            'estado' => EstadoReceta::class,
        ];
    }

    public function indicesCiegos(): array
    {
        // Ninguno: dos recetas del mismo remedio son lo normal en un crónico.
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
     * @return BelongsTo<Medico, $this>
     */
    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Medicamento, $this>
     */
    public function medicamento(): BelongsTo
    {
        return $this->belongsTo(Medicamento::class)->withTrashed();
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->paciente;
    }

    /**
     * El último día en que sirve. Fecha de CALENDARIO: se compara contra
     * `hoyCalendario()`, nunca contra `hoy()`.
     */
    public function vence(): CarbonImmutable
    {
        return $this->fecha->addDays($this->vigencia_dias);
    }

    public function estaVencida(User $usuario): bool
    {
        return $this->vence()->lt($usuario->hoyCalendario());
    }

    /**
     * Lo que se puede usar hoy: sin usar y sin vencer.
     */
    public function estaDisponible(User $usuario): bool
    {
        return $this->estado === EstadoReceta::Disponible && ! $this->estaVencida($usuario);
    }
}
