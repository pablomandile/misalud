<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un medicamento de "una vez por año" pasa de pedir una fecha completa a pedir
 * día y mes: la próxima aplicación se calcula sola cada año (ver
 * `Tratamiento::proximaAplicacion()`). Lo que ya estaba cargado conserva su día
 * y su mes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tratamientos', function (Blueprint $tabla): void {
            $tabla->unsignedTinyInteger('aplicacion_dia')->nullable()->after('horas');
            $tabla->unsignedTinyInteger('aplicacion_mes')->nullable()->after('aplicacion_dia');
        });

        DB::table('tratamientos')
            ->whereNotNull('proxima_aplicacion')
            ->select(['id', 'proxima_aplicacion'])
            ->lazyById()
            ->each(function (object $fila): void {
                $fecha = strtotime((string) $fila->proxima_aplicacion);

                DB::table('tratamientos')->where('id', $fila->id)->update([
                    'aplicacion_dia' => (int) date('j', (int) $fecha),
                    'aplicacion_mes' => (int) date('n', (int) $fecha),
                ]);
            });

        Schema::table('tratamientos', function (Blueprint $tabla): void {
            $tabla->dropColumn('proxima_aplicacion');
        });
    }

    public function down(): void
    {
        Schema::table('tratamientos', function (Blueprint $tabla): void {
            $tabla->date('proxima_aplicacion')->nullable()->after('horas');
            $tabla->dropColumn(['aplicacion_dia', 'aplicacion_mes']);
        });
    }
};
