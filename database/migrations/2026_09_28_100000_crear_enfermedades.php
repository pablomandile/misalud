<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que tiene o tuvo un paciente.
 *
 * ## `medico_id` va con `nullOnDelete`, y no es un descuido
 *
 * Es la **segunda** FK del proyecto que apunta a un catálogo, y va distinto
 * que la primera a propósito:
 *
 * - `mediciones.tipo_medicion_id` es **esencial**: sin su tipo, una medición
 *   es un número sin unidad ni nombre. Por eso va con `restrictOnDelete` y
 *   además el controlador frena el borrado con un mensaje.
 * - `enfermedades.medico_id` es **opcional**: quién la diagnosticó es un
 *   dato más, y una enfermedad sin médico se lee perfectamente. Con
 *   `nullOnDelete`, si el médico desaparece de verdad -se borra la cuenta
 *   que lo tenía-, la enfermedad sobrevive sin él en vez de bloquear el
 *   borrado de una cuenta ajena.
 *
 * La regla, para las que vengan: **bloquear el borrado cuando la referencia
 * es imprescindible para leer el registro; dejarla ir cuando es metadato.**
 *
 * Para el caso normal -un soft delete del catálogo- ninguna de las dos
 * alcanza: la fila sigue existiendo y la FK no se entera. Eso lo cubre el
 * `withTrashed()` de la relación en el modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enfermedades', function (Blueprint $tabla): void {
            $tabla->id();

            // NO fillable: de esta FK cuelga toda la autorización.
            $tabla->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();

            $tabla->foreignId('medico_id')->nullable()->constrained('medicos')->nullOnDelete();

            $tabla->text('nombre');

            // En claro, como todas las fechas: es por donde ordena la lista.
            // `date` y no `datetime`: nadie recuerda la hora de un diagnóstico.
            $tabla->date('fecha_diagnostico')->nullable();

            // String y no ENUM de MySQL: sumar un caso no obliga a acordarse
            // de una migración (ver CLAUDE.md).
            $tabla->string('estado', 20);

            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index(['paciente_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enfermedades');
    }
};
