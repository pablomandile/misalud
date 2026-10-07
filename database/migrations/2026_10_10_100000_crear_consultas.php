<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una consulta: la visita al médico, y lo que se dijo.
 *
 * Completa el circuito que faltaba: el turno es lo que se agenda ANTES, el
 * estudio el resultado de DESPUÉS, y esto es la visita en sí —donde queda lo
 * que el médico explicó, que es justo lo que se olvida saliendo del
 * consultorio—. La grabación no es una columna: es un adjunto `audio_consulta`
 * colgado de acá (ver `ArchivoService::guardarAudio()`).
 *
 * Las cuatro FK son metadato —la consulta se lee igual sin ellas—, así que van
 * con `nullOnDelete` (regla de la Etapa 7). El médico y el centro igual frenan
 * su borrado desde la pantalla, como en el resto (ver "Borrar un catálogo").
 *
 * `cobertura_id`, que traía el plan, no se creó: nada lo muestra ni lo usa, y
 * las coberturas ahora se borran de verdad. Entra el día que haga falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultas', function (Blueprint $tabla): void {
            $tabla->id();

            // NO fillable: de esta FK cuelga toda la autorización.
            $tabla->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();

            $tabla->foreignId('medico_id')->nullable()->constrained('medicos')->nullOnDelete();
            $tabla->foreignId('centro_id')->nullable()->constrained('centros')->nullOnDelete();
            $tabla->foreignId('enfermedad_id')->nullable()->constrained('enfermedades')->nullOnDelete();
            $tabla->foreignId('turno_id')->nullable()->constrained('turnos')->nullOnDelete();

            // En claro: ordena el listado. Un instante (`datetime`), el tercero
            // que carga una persona: `aUtc()` al guardar, `enSuZona()` al mostrar.
            $tabla->dateTime('fecha_hora');

            $tabla->text('motivo')->nullable();
            // Lo que dijo el médico. El campo que justifica la tabla.
            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index(['paciente_id', 'fecha_hora']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultas');
    }
};
