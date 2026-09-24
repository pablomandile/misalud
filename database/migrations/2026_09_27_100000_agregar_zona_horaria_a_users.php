<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La zona horaria de cada persona.
 *
 * Llega recién acá, con `mediciones`, porque es el **primer `datetime` que
 * carga una persona**: hasta ahora todo lo que tenía fecha era o una columna
 * `date` (nacimiento, vigencia de una cobertura) o un `timestamp` que pone el
 * servidor. Un `date` no tiene hora que corregir, y un timestamp de servidor
 * ya nace en UTC.
 *
 * Una medición no: "presión a las 23:30" tomada en Buenos Aires son las 02:30
 * UTC **del día siguiente**. Sin esta columna, guardar la hora local como si
 * fuera UTC corre cada registro tres horas y rompe -en silencio- tanto el
 * orden del día como cualquier cosa que agrupe por fecha.
 *
 * Default `America/Argentina/Buenos_Aires`: la app es argentina (voseo,
 * `es_AR`). No es una suposición universal, es el valor que sirve sin
 * preguntar nada a quien se acaba de registrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $tabla): void {
            $tabla->string('zona_horaria', 64)
                ->default('America/Argentina/Buenos_Aires')
                ->after('tamanio_texto');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $tabla): void {
            $tabla->dropColumn('zona_horaria');
        });
    }
};
