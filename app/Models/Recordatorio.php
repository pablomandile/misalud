<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\PerteneceAPaciente;
use App\Enums\EstadoRecordatorio;
use App\Enums\TipoRecordatorio;
use App\Policies\RegistroClinicoPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Un aviso pendiente, generado siempre por un observer a partir de su origen.
 *
 * ## ⚠️ Es el primer modelo del dominio clínico que NO cifra nada
 *
 * Y es a propósito. Todas sus columnas son metadato del sistema: de qué tipo
 * es el aviso, cuándo hay que darlo, de qué fila salió, en qué estado está.
 * **El contenido no vive acá**: el texto que se le muestra a la persona sale
 * del origen -el motivo del turno, el medicamento del tratamiento-, que sí
 * está cifrado en su propia tabla. Si algún día un recordatorio necesitara
 * un texto propio, esa columna tendría que ir cifrada y el modelo pasaría a
 * implementar `CifraDatos` como todos los demás.
 *
 * Por eso tampoco declara `$builder = ConsultaVigilada::class`: no hay
 * ninguna columna cifrada sobre la que un `where` pueda mentir.
 *
 * ## ⚠️ Y por eso NO tiene factory, aunque tentaría
 *
 * Una `RecordatorioFactory` tendría que inventarse un origen, y el camino
 * corto -crear un `Turno`- **choca contra el UNIQUE**: el observer de ese
 * turno ya generó su recordatorio antes de que la factory llegue a insertar
 * el suyo. No es un detalle a tapar con un `firstOrCreate`: es la tabla
 * avisando que solo tiene un camino de escritura, que es justo para lo que
 * se diseñó.
 *
 * En un test se crea el turno y se deja que el observer trabaje -eso prueba
 * el camino de verdad-; para forzar un estado que tardaría en producirse
 * (uno ya enviado, uno vencido) se usa `forceFill()`, que además queda
 * explícito como "esto no es un camino normal".
 *
 * @property int $id
 * @property int $paciente_id
 * @property TipoRecordatorio $tipo
 * @property CarbonImmutable $fecha
 * @property string $origen_type
 * @property int $origen_id
 * @property EstadoRecordatorio $estado
 * @property CarbonImmutable|null $enviado_en
 * @property CarbonImmutable|null $fecha_completado
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class Recordatorio extends Model implements PerteneceAPaciente
{
    protected $table = 'recordatorios';

    /*
     * Nada es fillable, y es la consecuencia directa de que **esta tabla no
     * la escribe ningún formulario**: la llena `GeneradorDeRecordatorios`
     * con `setAttribute()`, y lo único que toca una persona es el estado,
     * por `RecordatorioController::update()`. Dejar campos fillable acá
     * sería abrir un segundo camino de escritura para una tabla cuyo valor
     * entero es que solo tiene uno.
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoRecordatorio::class,
            'estado' => EstadoRecordatorio::class,
            'fecha' => 'datetime',
            'enviado_en' => 'datetime',
            'fecha_completado' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Paciente, $this>
     */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    /**
     * De dónde salió: un `Turno`, un `Tratamiento`, y lo que se sume.
     *
     * @return MorphTo<Model, $this>
     */
    public function origen(): MorphTo
    {
        return $this->morphTo();
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->paciente;
    }

    /**
     * Marcarlo hecho. Es **lo único** que una persona puede cambiarle.
     */
    public function completar(): void
    {
        $this->setAttribute('estado', EstadoRecordatorio::Completado);
        $this->setAttribute('fecha_completado', Carbon::now());
        $this->save();
    }

    /**
     * Volver a dejarlo abierto, para quien lo marcó hecho por error.
     *
     * ⚠️ **No borra `enviado_en`.** El mail ya se mandó, y eso es un hecho
     * del pasado: limpiarlo haría que el comando lo mandara de nuevo, que es
     * justo lo que `enviado_en` existe para evitar. Lo único que se
     * reabre es el estado de la persona.
     */
    public function reabrir(): void
    {
        $this->setAttribute(
            'estado',
            $this->enviado_en === null
                ? EstadoRecordatorio::Pendiente
                : EstadoRecordatorio::Enviado,
        );
        $this->setAttribute('fecha_completado', null);
        $this->save();
    }
}
