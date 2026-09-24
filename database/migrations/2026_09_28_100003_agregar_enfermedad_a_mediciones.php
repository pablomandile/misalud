<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El vínculo entre una medición y la enfermedad que se está siguiendo.
 *
 * Llega recién acá, y no con `mediciones` en el paso 6.1, **a propósito**:
 * ahí la tabla `enfermedades` no existía, así que la columna habría quedado
 * sin FK, sin validación, sin nadie que la escriba y sin un test que la
 * pudiera proteger. Sumarla ahora no cuesta ningún backfill —todas las
 * filas existentes tienen que ser NULL de verdad— y llega con su
 * restricción puesta.
 *
 * `nullOnDelete` y no `cascade`: la medición es un dato por derecho propio.
 * Si se borra la enfermedad, el peso de ese día **sigue siendo el peso de
 * ese día**; lo que se pierde es el vínculo, no el registro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mediciones', function (Blueprint $tabla): void {
            $tabla->foreignId('enfermedad_id')
                ->nullable()
                ->after('tipo_medicion_id')
                ->constrained('enfermedades')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mediciones', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('enfermedad_id');
        });
    }
};
