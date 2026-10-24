<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La toma suma "una vez por semana" y "algunos días de la semana", y para la
 * segunda, cuáles: `dias_semana` es una lista de días ISO (1 = lunes, 7 =
 * domingo). En claro, como la periodicidad: dice cuándo, no qué.
 *
 * ⚠️ El ENUM se ensancha acá: los casos nuevos de `PeriodicidadToma` solos
 * pasan los tests y revientan en MySQL con un 500 al primer guardado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tratamientos', function (Blueprint $tabla): void {
            $tabla->enum('periodicidad', ['diaria', 'semanal', 'personalizada', 'mensual', 'anual', 'unica'])
                ->nullable()
                ->change();
            $tabla->json('dias_semana')->nullable()->after('periodicidad');
        });
    }

    public function down(): void
    {
        Schema::table('tratamientos', function (Blueprint $tabla): void {
            $tabla->dropColumn('dias_semana');
        });
    }
};
