<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que hay que recordarle a alguien, y de dónde salió.
 *
 * ## Los genera un observer, nunca un formulario
 *
 * No hay `store` ni `destroy` en ningún controlador: un recordatorio existe
 * porque existe su origen, y desaparece con él. Lo único que hace una
 * persona es marcarlo como hecho. Esa es la decisión que hace que la tabla
 * se pueda confiar: no hay dos caminos por los que una fila pueda nacer.
 *
 * ## El UNIQUE es la idempotencia, y está en la base
 *
 * `unique(origen_type, origen_id, tipo)`. Un observer corre en **cada**
 * guardado de su origen, así que "generar el recordatorio" tiene que ser una
 * operación que se pueda repetir mil veces sin duplicar nada. El código lo
 * resuelve buscando antes de crear (`GeneradorDeRecordatorios`), pero el
 * UNIQUE está igual: es lo que convierte un bug de lógica en un error
 * ruidoso en vez de una bandeja con el mismo aviso repetido.
 *
 * ⚠️ **`paciente_id` NO entra en el UNIQUE**, y no es un olvido: el par
 * (`origen_type`, `origen_id`) ya determina de qué paciente es. Sumarlo
 * permitiría dos filas que solo difieren en el paciente para el mismo
 * origen, que es precisamente el estado imposible que hay que prohibir.
 *
 * `paciente_id` está igual, denormalizado, porque de él cuelga la
 * autorización y porque la consulta de "los pendientes de esta ficha" no
 * puede depender de resolver un polimórfico por cada fila.
 *
 * ## Sin soft deletes
 *
 * Es dato **derivado**: si se borra, el observer lo vuelve a crear con el
 * próximo guardado del origen. Un soft delete acá solo lograría que el
 * UNIQUE viera una fila fantasma y bloqueara la regeneración -el mismo
 * problema que tiene cualquier UNIQUE convivendo con `deleted_at`-.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recordatorios', function (Blueprint $tabla): void {
            $tabla->id();

            // NO fillable: de esta FK cuelga toda la autorización.
            $tabla->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();

            // En claro: es una categoría del sistema, no contenido clínico.
            $tabla->string('tipo', 40);

            /*
             * Cuándo hay que avisar, ya con la anticipación restada (ver
             * `TipoRecordatorio::horasDeAnticipacion()`). En claro y
             * `datetime`: es por donde consulta el comando horario.
             */
            $tabla->dateTime('fecha');

            // De qué salió. Polimórfico y en claro: un turno, un tratamiento,
            // y lo que venga -una dosis de vacuna, una receta que vence-.
            $tabla->string('origen_type');
            $tabla->unsignedBigInteger('origen_id');

            $tabla->string('estado', 20);

            /*
             * Dos marcas de tiempo para dos historias distintas: una es del
             * sistema (¿se mandó el mail?) y la otra de la persona (¿lo
             * resolvió?). Con una sola columna habría que adivinar cuál de
             * las dos cosas pasó.
             */
            $tabla->dateTime('enviado_en')->nullable();
            $tabla->dateTime('fecha_completado')->nullable();

            $tabla->timestamps();

            // La idempotencia, garantizada por la base y no solo por el código.
            $tabla->unique(['origen_type', 'origen_id', 'tipo']);

            // Por acá entra el comando horario: los que ya vencieron su hora
            // y todavía están abiertos.
            $tabla->index(['estado', 'fecha']);

            $tabla->index('paciente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recordatorios');
    }
};
