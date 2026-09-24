<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El resultado: lo que se hizo, después de la orden.
 *
 * `medico_id` y `centro_id` son metadato -quién lo pidió, dónde se hizo-,
 * así que van con `nullOnDelete` (la regla de la Etapa 7: bloquear cuando
 * la referencia es imprescindible para leer el registro, dejarla ir cuando
 * no). `enfermedad_id` sigue el mismo criterio que en mediciones y
 * tratamientos: opcional, y tiene que ser del MISMO paciente -eso lo valida
 * el FormRequest, acá solo la FK-.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estudios', function (Blueprint $tabla): void {
            $tabla->id();

            // NO fillable: de esta FK cuelga toda la autorización.
            $tabla->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();

            $tabla->foreignId('medico_id')->nullable()->constrained('medicos')->nullOnDelete();
            $tabla->foreignId('centro_id')->nullable()->constrained('centros')->nullOnDelete();
            $tabla->foreignId('enfermedad_id')->nullable()->constrained('enfermedades')->nullOnDelete();

            $tabla->text('tipo');

            // En claro: por donde se ordena la lista.
            $tabla->date('fecha');

            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index('paciente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estudios');
    }
};
