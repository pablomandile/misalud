<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tratamientos pasa a ser "Medicamentos" en pantalla: lo que toma cada
 * paciente. Suma la periodicidad de la toma (en claro, es un enum) y deja la
 * frecuencia opcional: una toma única no tiene "cada cuánto".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tratamientos', function (Blueprint $tabla): void {
            $tabla->enum('periodicidad', ['diaria', 'mensual', 'anual', 'unica'])->nullable()->after('frecuencia');
            $tabla->text('frecuencia')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tratamientos', function (Blueprint $tabla): void {
            $tabla->dropColumn('periodicidad');
        });
    }
};
