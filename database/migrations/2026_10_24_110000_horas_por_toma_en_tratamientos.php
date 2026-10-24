<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un horario por toma: "2 veces por día, a las 08:00 y 20:00".
 * `tomas_por_dia` es el contador (solo con "todos los días" o "algunos días de
 * la semana") y `horas` la lista de horarios de reloj, "HH:MM", sin zona. Puede
 * haber menos horarios que tomas: el horario es opcional, la cantidad no.
 *
 * Reemplaza a `hora`, que tenía uno solo; lo que estaba cargado pasa a la lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tratamientos', function (Blueprint $tabla): void {
            $tabla->unsignedTinyInteger('tomas_por_dia')->nullable()->after('dias_semana');
            $tabla->json('horas')->nullable()->after('tomas_por_dia');
        });

        DB::table('tratamientos')->whereNotNull('hora')->orderBy('id')->each(function (object $fila): void {
            DB::table('tratamientos')->where('id', $fila->id)->update([
                'horas' => json_encode([substr((string) $fila->hora, 0, 5)]),
            ]);
        });

        Schema::table('tratamientos', function (Blueprint $tabla): void {
            $tabla->dropColumn('hora');
        });
    }

    public function down(): void
    {
        Schema::table('tratamientos', function (Blueprint $tabla): void {
            $tabla->time('hora')->nullable()->after('dias_semana');
        });

        DB::table('tratamientos')->whereNotNull('horas')->orderBy('id')->each(function (object $fila): void {
            $horas = json_decode((string) $fila->horas, true);
            DB::table('tratamientos')->where('id', $fila->id)->update(['hora' => $horas[0] ?? null]);
        });

        Schema::table('tratamientos', function (Blueprint $tabla): void {
            $tabla->dropColumn(['tomas_por_dia', 'horas']);
        });
    }
};
