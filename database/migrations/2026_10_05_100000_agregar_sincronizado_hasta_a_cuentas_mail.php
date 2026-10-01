<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasta dónde se sincronizó esta casilla.
 *
 * Es la columna que el paso 12.1 **dejó afuera a propósito** —ahí la regla era
 * "configuración sí, estado no", y nada la escribía todavía—. Ahora existe el
 * comando que la escribe, así que llega con su FK, su validación implícita y
 * sus tests. Mismo criterio que `ordenes_estudio.estudio_id`, que apareció en
 * el 9.2 y no en el 9.1.
 *
 * `NULL` significa "nunca se sincronizó", y es lo que distingue la primera
 * corrida —que mira `misalud.recetas.dias_iniciales` hacia atrás— de todas las
 * demás, que miran desde acá menos el solapamiento.
 *
 * En claro y en UTC, como todas las fechas del proyecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas_mail', function (Blueprint $tabla): void {
            $tabla->dateTime('sincronizado_hasta')->nullable()->after('filtros');
        });
    }

    public function down(): void
    {
        Schema::table('cuentas_mail', function (Blueprint $tabla): void {
            $tabla->dropColumn('sincronizado_hasta');
        });
    }
};
