<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La bitácora de una enfermedad: qué fue pasando, en texto.
 *
 * ⚠️ **Acá NO van números medibles.** Tentaba darle `valor` y `unidad` para
 * poder anotar "hoy 140/90" sin salir de la ficha, y sería un error: el
 * mismo dato viviría en dos tablas y la curva de presión saldría partida
 * según dónde se lo cargó ese día. Los números van a `mediciones`, que
 * puede apuntar a esta enfermedad por `enfermedad_id`; así la ficha muestra
 * su propia curva sin duplicar nada.
 *
 * `cascadeOnDelete` sobre `enfermedad_id`, al revés que las FK a catálogos:
 * una entrada de bitácora **no existe sin su enfermedad**, no es un registro
 * que pueda quedar suelto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registros_enfermedad', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('enfermedad_id')->constrained('enfermedades')->cascadeOnDelete();

            // `date` y no `datetime`: una anotación es de un día.
            $tabla->date('fecha');
            $tabla->text('nota');

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index(['enfermedad_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registros_enfermedad');
    }
};
