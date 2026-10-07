<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las dosis de vacuna que se aplicó un paciente: el carnet de vacunación.
 *
 * El catálogo `vacunas` es solo el nombre, compartido entre dosis y pacientes;
 * el registro clínico real es esto. Por eso el comprobante cuelga de acá y no
 * del catálogo (ver el modelo `Vacuna`).
 *
 * ## Las tres FK, con la regla de la Etapa 7
 *
 * > Bloquear el borrado cuando la referencia es imprescindible para leer el
 * > registro; dejarla ir cuando es metadato.
 *
 * - `vacuna_id`: una dosis "de algo" no se puede leer → `restrictOnDelete`, y
 *   `VacunaController::usos()` lo frena antes con un mensaje.
 * - `centro_id`: dónde se la dio es metadato → `nullOnDelete`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aplicaciones_vacuna', function (Blueprint $tabla): void {
            $tabla->id();

            // NO fillable: de esta FK cuelga toda la autorización.
            $tabla->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();

            $tabla->foreignId('vacuna_id')->constrained('vacunas')->restrictOnDelete();
            $tabla->foreignId('centro_id')->nullable()->constrained('centros')->nullOnDelete();

            /*
             * En claro: ordenan el carnet y deciden el recordatorio. Las dos son
             * fechas de CALENDARIO (`date`): una vacuna se anota por día, y es
             * lo que trae el carnet de papel.
             */
            $tabla->date('fecha');
            $tabla->date('proxima_dosis')->nullable();

            // "1ª dosis", "refuerzo", "anual": texto, porque cada esquema numera distinto.
            $tabla->text('dosis')->nullable();
            $tabla->text('lote')->nullable();
            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index(['paciente_id', 'vacuna_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aplicaciones_vacuna');
    }
};
