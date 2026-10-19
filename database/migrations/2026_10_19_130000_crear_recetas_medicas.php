<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las recetas de medicamentos que firma el médico, en la ficha del paciente.
 *
 * ⚠️ No es la tabla `recetas`: esa es la bandeja de lo que llega por mail, y
 * cuelga del USUARIO porque un mail no dice de quién es la receta. Esta la
 * carga una persona sabiendo para quién es, así que cuelga del PACIENTE y la
 * autoriza el pivote, como el resto de la historia clínica.
 *
 * `medicamento_id` y `detalle` son opcionales por separado pero no los dos a la
 * vez (lo valida el FormRequest): el medicamento del catálogo trae su prospecto,
 * y el detalle dice lo que el catálogo no ("2 cajas", "x 30 comprimidos").
 * Médico y medicamento son metadato: `nullOnDelete`, y los dos figuran en los
 * `usos()` de su catálogo, así que desde la pantalla no se pueden borrar.
 *
 * "Vencida" no se guarda: sale de `fecha + vigencia_dias`, igual que en la
 * bandeja (regla 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recetas_medicas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();
            $tabla->foreignId('medico_id')->nullable()->constrained('medicos')->nullOnDelete();
            $tabla->foreignId('medicamento_id')->nullable()->constrained('medicamentos')->nullOnDelete();

            $tabla->text('detalle')->nullable();
            $tabla->date('fecha');
            $tabla->unsignedSmallInteger('vigencia_dias')->default(30);
            $tabla->enum('estado', ['disponible', 'usada'])->default('disponible');
            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index('paciente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recetas_medicas');
    }
};
