<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A qué es alérgico un paciente.
 *
 * Tabla propia y no una nota suelta en `pacientes`: son varias, cada una
 * con su reacción y su severidad, y es de lo primero que se pregunta en una
 * guardia. Un campo de texto libre con todo junto no se puede mostrar
 * ordenado ni buscar después.
 *
 * `severidad` la carga la persona con lo que le dijo su médico. El sistema
 * no la deduce de nada ni la usa para avisar (regla 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alergias', function (Blueprint $tabla): void {
            $tabla->id();

            // NO fillable: de esta FK cuelga toda la autorización.
            $tabla->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();

            $tabla->text('sustancia');
            $tabla->char('sustancia_hash', 64);

            $tabla->text('reaccion')->nullable();
            $tabla->string('severidad', 20);
            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index('paciente_id');

            /*
             * No puede haber dos "Penicilina" para el mismo paciente: sería
             * la misma alergia cargada dos veces, y con severidades
             * distintas no se sabría cuál vale. Va por el índice ciego
             * porque `sustancia` está cifrada (ver `IndiceCiegoUnico`).
             */
            $tabla->unique(['paciente_id', 'sustancia_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alergias');
    }
};
