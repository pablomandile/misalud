<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Para un medicamento de "una vez por año": la fecha de la próxima
 * aplicación, de la que salen dos avisos por mail (un mes antes y una semana
 * antes). Fecha de calendario, en claro: dice cuándo, no qué.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tratamientos', function (Blueprint $tabla): void {
            $tabla->date('proxima_aplicacion')->nullable()->after('horas');
        });
    }

    public function down(): void
    {
        Schema::table('tratamientos', function (Blueprint $tabla): void {
            $tabla->dropColumn('proxima_aplicacion');
        });
    }
};
