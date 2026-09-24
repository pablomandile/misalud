<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los números de un ojo. **Siempre son dos filas por receta**, aunque un ojo
 * no necesite corrección: un papel de óptica trae las dos líneas, y una
 * receta con una sola fila no se sabría leer -¿el otro ojo está sano, o
 * quedó sin cargar?-. Un ojo sin datos se guarda con todo en `null` y la
 * pantalla dice "sin datos" (regla 2), que es una respuesta y no un hueco.
 *
 * Lo garantiza el `unique(prescripcion_id, ojo)` de abajo más la transacción
 * de `PrescripcionOcularController::store()`.
 *
 * ## El único UNIQUE del proyecto que no necesitó índice ciego
 *
 * `prescripcion_id` y `ojo` están **en claro** -uno es una FK, el otro un
 * enum de dos casos-, así que el UNIQUE es un UNIQUE común y corriente. Es
 * la excepción que confirma para qué existen los `*_hash`: no se necesita
 * uno cuando lo que hay que comparar no es contenido clínico.
 *
 * ## Sin soft deletes, a propósito
 *
 * Una graduación no se borra sola: nace y muere con su receta, y no hay
 * ninguna acción de "borrar un ojo". `cascadeOnDelete` cubre el borrado
 * definitivo; el soft delete de la receta no toca estas filas -⚠️ la cascada
 * de MySQL no dispara con un soft delete- y es justo lo que hace falta para
 * que restaurarla la devuelva completa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graduaciones_oculares', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('prescripcion_id')
                ->constrained('prescripciones_oculares')
                ->cascadeOnDelete();

            // En claro: 'od' | 'oi'. No es contenido clínico, es cuál de los
            // dos, y es la mitad del UNIQUE.
            $tabla->string('ojo', 2);

            /*
             * Todo lo demás, cifrado: son los números de la salud de una
             * persona. Y por estar cifrados son TEXTO -no existe un cast
             * `encrypted:float`-, así que ninguna cuenta puede salir de leer
             * la columna directo. Ver `GraduacionOcular::numero()`.
             */
            $tabla->text('esfera')->nullable();
            $tabla->text('cilindro')->nullable();
            $tabla->text('eje')->nullable();
            $tabla->text('adicion')->nullable();
            $tabla->text('dp_monocular')->nullable();
            $tabla->text('prisma')->nullable();
            $tabla->text('base')->nullable();
            $tabla->text('agudeza_visual')->nullable();

            $tabla->timestamps();

            $tabla->unique(['prescripcion_id', 'ojo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graduaciones_oculares');
    }
};
