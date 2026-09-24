<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La receta de anteojos: la cabecera. Los números van en
 * `graduaciones_oculares`, una fila por ojo.
 *
 * ## Por qué dos tablas y no ocho columnas por ojo acá
 *
 * Con `esfera_od`, `esfera_oi`, `cilindro_od`… serían dieciséis columnas
 * cifradas y toda pantalla, validación y gráfico tendría que repetir la
 * misma lógica dos veces con distinto sufijo. Con dos filas, "la graduación
 * de un ojo" es un objeto: se valida una vez, se dibuja una vez, y la
 * evolución de la Etapa 10.4 es un `groupBy('ojo')` en vez de dos consultas
 * paralelas.
 *
 * `medico_id` y `centro_id` -quién la hizo, en qué óptica- son metadato:
 * `nullOnDelete`, siguiendo la regla de la Etapa 7 (bloquear el borrado solo
 * cuando la referencia es imprescindible para poder leer el registro).
 *
 * `dp_total` es la distancia pupilar de los dos ojos juntos, en milímetros.
 * Va acá y no en cada ojo porque es una sola medida de la cara; la
 * monocular, cuando la receta la trae, va en cada graduación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescripciones_oculares', function (Blueprint $tabla): void {
            $tabla->id();

            // NO fillable: de esta FK cuelga toda la autorización.
            $tabla->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();

            $tabla->foreignId('medico_id')->nullable()->constrained('medicos')->nullOnDelete();
            $tabla->foreignId('centro_id')->nullable()->constrained('centros')->nullOnDelete();

            // En claro: es una categoría, no contenido clínico, y la pantalla
            // agrupa por ella. `string` y no ENUM de MySQL, ver el enum.
            $tabla->string('tipo', 20);

            // En claro: es por donde ordena el listado y por donde va a
            // paginar el gráfico de evolución.
            $tabla->date('fecha');

            $tabla->text('dp_total')->nullable();
            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index(['paciente_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescripciones_oculares');
    }
};
