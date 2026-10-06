<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se usó una receta.
 *
 * El paso 12.2 la dejó afuera **a propósito**, con la regla "entra la columna
 * que la importación escribe": la importación nunca marca una receta como
 * usada. Llega ahora junto con la única acción que la escribe —marcarla usada
 * desde la bandeja—, y por eso viene con su validación y sus tests y no como
 * una columna vacía esperando.
 *
 * En claro, como todas las fechas: es un instante del sistema, no contenido
 * clínico, y la bandeja ordena el historial por ella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recetas', function (Blueprint $tabla): void {
            $tabla->dateTime('fecha_uso')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('recetas', function (Blueprint $tabla): void {
            $tabla->dropColumn('fecha_uso');
        });
    }
};
