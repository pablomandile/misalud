<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una visita agendada: cuándo, con quién, dónde y por qué.
 *
 * ## `fecha_hora` es un `datetime`, y puede estar en el FUTURO
 *
 * Es el segundo `datetime` que carga una persona en esta app -el primero fue
 * `mediciones.fecha`, paso 6.1-, así que corre la misma regla: `aUtc()` al
 * guardar, `enSuZona()` al mostrar, y la conversión vive en el FormRequest
 * para que no se pueda olvidar en una acción nueva.
 *
 * Lo que cambia respecto de una medición: **acá el futuro es el caso
 * normal**, no un error. Una medición registra algo que ya pasó y por eso
 * rechaza mañana; un turno se agenda justamente para mañana. Así que no
 * lleva ninguna validación de rango: un turno viejo también es válido -sirve
 * para registrar que se fue-.
 *
 * ## Las tres FK son metadato
 *
 * `medico_id`, `centro_id` y `orden_estudio_id` van con `nullOnDelete`
 * siguiendo la regla de la Etapa 7: un turno sin médico se lee igual ("29/9
 * a las 10, análisis"), así que ninguna es imprescindible para entenderlo.
 *
 * `orden_estudio_id` es lo que cierra el circuito orden → turno → estudio
 * que la Etapa 9 dejó a medias: la orden dice qué hay que hacerse, el turno
 * dice cuándo, y el estudio es el resultado. Tiene que ser una orden del
 * MISMO paciente, y eso lo valida el FormRequest -acá solo la FK-.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnos', function (Blueprint $tabla): void {
            $tabla->id();

            // NO fillable: de esta FK cuelga toda la autorización.
            $tabla->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();

            $tabla->foreignId('medico_id')->nullable()->constrained('medicos')->nullOnDelete();
            $tabla->foreignId('centro_id')->nullable()->constrained('centros')->nullOnDelete();
            $tabla->foreignId('orden_estudio_id')->nullable()
                ->constrained('ordenes_estudio')->nullOnDelete();

            /*
             * En claro, como todas las fechas: es por donde ordena la agenda
             * y por donde el observer calcula cuándo avisar. Y es un
             * `datetime` y no un `date` porque la hora ES el dato -un turno
             * a las 8 y uno a las 18 no son el mismo turno-.
             */
            $tabla->dateTime('fecha_hora');

            $tabla->text('motivo')->nullable();

            // En claro: es un estado, no contenido clínico, y la pantalla
            // agrupa por él.
            $tabla->string('estado', 20);

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index(['paciente_id', 'fecha_hora']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('turnos');
    }
};
