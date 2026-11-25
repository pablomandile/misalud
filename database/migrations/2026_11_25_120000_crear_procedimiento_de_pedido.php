<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El procedimiento de pedido de un medicamento: las etapas que hay que cumplir
 * cada vez que se lo pide (la receta, la autorización de la obra social, retirarlo
 * en la farmacia), y el seguimiento de cada pedido.
 *
 * Todo cuelga del medicamento DE LA FICHA (`tratamientos`), no del catálogo: el
 * Losartán de dos familiares tiene obra social, médico y farmacia distintos.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La definición. Sin papelera: es configuración, borrar borra.
        Schema::create('etapas_procedimiento', function (Blueprint $tabla): void {
            $tabla->id();

            // NO fillable: de esta FK cuelga la autorización. Cascada: sin su
            // medicamento la etapa no significa nada, y con `restrict` vaciar la
            // papelera o eliminar la cuenta reventaría.
            $tabla->foreignId('tratamiento_id')->constrained('tratamientos')->cascadeOnDelete();
            $tabla->unsignedSmallInteger('orden');
            $tabla->text('nombre');

            $tabla->timestamps();

            $tabla->index(['tratamiento_id', 'orden']);
        });

        // Cada vuelta del procedimiento. `completado_el` NULL = en curso.
        Schema::create('pedidos_procedimiento', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('tratamiento_id')->constrained('tratamientos')->cascadeOnDelete();

            // En claro y de calendario: ordenan el historial.
            $tabla->date('iniciado_el');
            $tabla->date('completado_el')->nullable();

            $tabla->timestamps();

            $tabla->index(['tratamiento_id', 'completado_el']);
        });

        // El avance de cada etapa en un pedido.
        Schema::create('pasos_pedido', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('pedido_id')->constrained('pedidos_procedimiento')->cascadeOnDelete();

            // Vínculo con la etapa mientras exista. El historial no lo necesita:
            // lleva su propia foto del nombre.
            $tabla->foreignId('etapa_procedimiento_id')->nullable()->constrained('etapas_procedimiento')->nullOnDelete();

            $tabla->unsignedSmallInteger('orden');
            $tabla->text('nombre');
            $tabla->boolean('completada')->default(false);
            $tabla->date('completada_el')->nullable();
            $tabla->text('observaciones')->nullable();

            $tabla->timestamps();

            $tabla->index(['pedido_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pasos_pedido');
        Schema::dropIfExists('pedidos_procedimiento');
        Schema::dropIfExists('etapas_procedimiento');
    }
};
