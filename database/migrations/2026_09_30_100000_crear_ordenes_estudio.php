<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El papel que da el médico ANTES: "hacete un análisis de sangre".
 *
 * ## Por qué es una tabla y no un estudio a medio llenar
 *
 * Una orden y un estudio son **dos cosas distintas**, no dos estados de la
 * misma. La orden existe desde que el médico la firma y puede no
 * convertirse nunca en un estudio -se vence, cambia la indicación, uno no
 * va-. Modelarla como un `estudios` con los campos de resultado vacíos
 * obligaría a que la mitad de las columnas de esa tabla fueran nullable y,
 * peor, dejaría la pantalla de "pendientes de hacer" apoyada en "estudios
 * donde falta casi todo", que es una definición que se rompe sola en
 * cuanto alguien cargue un estudio incompleto por otro motivo.
 *
 * ## `estado` y el estudio: dos cosas que NO son la misma
 *
 * En el paso 9.2 esta tabla suma `estudio_id` para cerrar el circuito
 * orden → estudio. Cuando llegue, la regla es **de una sola dirección**:
 *
 * > Vincular un estudio implica `Hecha`; estar `Hecha` NO implica que haya
 * > un estudio cargado.
 *
 * Y no es un detalle: uno se hace el análisis y tarda semanas en subir el
 * PDF, o no lo sube nunca. Si "hecha" se dedujera de `estudio_id`, todas
 * esas órdenes seguirían apareciendo como pendientes y la pantalla que
 * justifica la tabla entera diría cualquier cosa.
 *
 * La columna `estudio_id` NO se crea acá **a propósito**: `estudios` todavía
 * no existe, así que quedaría sin FK, sin validación y sin nada que la
 * escriba —el mismo caso de `mediciones.enfermedad_id`, que se sumó recién
 * en la Etapa 7 y no costó ningún backfill—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes_estudio', function (Blueprint $tabla): void {
            $tabla->id();

            // NO fillable: de esta FK cuelga toda la autorización.
            $tabla->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();

            // Metadato -quién la indicó-, así que `nullOnDelete`: una orden
            // sin médico se lee igual (ver la regla en la Etapa 7).
            $tabla->foreignId('medico_id')->nullable()->constrained('medicos')->nullOnDelete();

            $tabla->text('estudio_solicitado');

            // En claro: es por donde se ordena la lista.
            $tabla->date('fecha');

            // String y no ENUM de MySQL: sumar un caso no obliga a acordarse
            // de una migración (ver CLAUDE.md).
            $tabla->string('estado', 20);

            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            // La consulta de la pantalla: "las pendientes de esta ficha".
            $tabla->index(['paciente_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_estudio');
    }
};
