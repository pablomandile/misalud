<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A qué hora se toma. Es una hora de RELOJ ("las 8 de la mañana"), no un
 * instante: no se pasa a UTC, porque las 8 son las 8 en la zona de quien la
 * toma. En claro, como la periodicidad: dice cuándo, no qué.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tratamientos', function (Blueprint $tabla): void {
            $tabla->time('hora')->nullable()->after('dias_semana');
        });
    }

    public function down(): void
    {
        Schema::table('tratamientos', function (Blueprint $tabla): void {
            $tabla->dropColumn('hora');
        });
    }
};
