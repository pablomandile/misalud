<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué medicamento toma un paciente, con qué dosis y por qué.
 *
 * ## `medicamento_id` es la TERCERA FK a un catálogo, y sigue la regla de la
 * Etapa 7
 *
 * > Bloquear el borrado cuando la referencia es imprescindible para leer el
 * > registro; dejarla ir cuando es metadato.
 *
 * Un tratamiento sin su medicamento es "500mg cada 8 horas" de nada: no se
 * puede leer. Va con `restrictOnDelete`, igual que `mediciones.tipo_medicion_id`,
 * y `MedicamentoController::destroy()` lo frena antes con un mensaje -la
 * restricción de la base es la última línea, no la primera, porque un soft
 * delete (el borrado normal de un catálogo) no la dispara-.
 *
 * `medico_id` y `enfermedad_id` son metadato -quién lo indicó, por qué-, así
 * que van con `nullOnDelete`: un tratamiento sobrevive sin ellos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tratamientos', function (Blueprint $tabla): void {
            $tabla->id();

            // NO fillable: de esta FK cuelga toda la autorización.
            $tabla->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();

            $tabla->foreignId('medicamento_id')->constrained('medicamentos')->restrictOnDelete();
            $tabla->foreignId('medico_id')->nullable()->constrained('medicos')->nullOnDelete();
            $tabla->foreignId('enfermedad_id')->nullable()->constrained('enfermedades')->nullOnDelete();

            $tabla->text('dosis');
            $tabla->text('frecuencia');

            // En claro: son por donde se ordena y se decide qué está vigente.
            // `date`, no `datetime`: un tratamiento se piensa por día, no por hora.
            $tabla->date('inicio');
            $tabla->date('fin')->nullable();

            $tabla->boolean('activo')->default(true);

            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index(['paciente_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tratamientos');
    }
};
