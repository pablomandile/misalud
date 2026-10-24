<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La sección «Tratamiento»: cada aplicación o toma de un medicamento que no es
 * diario, con la enfermedad por la que se hizo. Es la historia de lo que se
 * aplicó; el medicamento (`tratamientos`) dice qué se toma y cómo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aplicaciones_medicamento', function (Blueprint $tabla): void {
            $tabla->id();

            // NO fillable: de esta FK cuelga toda la autorización.
            $tabla->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();

            /*
             * Una aplicación "de nada" no se lee, así que se va con su
             * medicamento cuando ese se borra DE VERDAD (al vaciar la papelera).
             * Cascada y no `restrict`: con `restrict`, vaciar la papelera o
             * eliminar la cuenta reventaría por estas filas.
             */
            $tabla->foreignId('tratamiento_id')->constrained('tratamientos')->cascadeOnDelete();

            // Metadato: sin la enfermedad la aplicación se lee igual.
            $tabla->foreignId('enfermedad_id')->nullable()->constrained('enfermedades')->nullOnDelete();

            // En claro: ordena el historial. Fecha de calendario.
            $tabla->date('fecha');
            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index(['paciente_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aplicaciones_medicamento');
    }
};
