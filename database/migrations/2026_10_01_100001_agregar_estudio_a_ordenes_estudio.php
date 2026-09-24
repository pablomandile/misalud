<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierra el circuito orden → estudio.
 *
 * Llega recién ahora -y no en el paso 9.1, cuando se creó `ordenes_estudio`-
 * por lo mismo que `mediciones.enfermedad_id` llegó en la Etapa 7: la tabla
 * a la que apunta no existía todavía, así que antes hubiera quedado sin FK
 * y sin nada que la escriba.
 *
 * `nullOnDelete`: si el estudio se borra, la orden -que es el papel real
 * que existió- sobrevive. Solo pierde el vínculo, no su lugar en la
 * historia.
 *
 * ⚠️ **Es una relación de una sola dirección**, documentada en CLAUDE.md:
 * vincular un estudio pone la orden en `Hecha`, pero una orden `Hecha`
 * NO implica que tenga un estudio cargado -uno se hace el análisis y tarda
 * semanas en subir el PDF, o no lo sube nunca-. `estado` y `estudio_id` se
 * escriben juntos cuando se vincula, pero nunca se deduce uno del otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes_estudio', function (Blueprint $tabla): void {
            $tabla->foreignId('estudio_id')
                ->nullable()
                ->after('paciente_id')
                ->constrained('estudios')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ordenes_estudio', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('estudio_id');
        });
    }
};
