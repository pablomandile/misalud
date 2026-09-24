<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué se puede medir: peso, presión, glucemia, temperatura…
 *
 * Es el **quinto catálogo** y sigue el patrón de los otros cuatro sin
 * cambiarle nada (ver CLAUDE.md, sección Catálogos). La diferencia es que
 * acá las semillas sí son el caso normal: casi nadie define un tipo propio,
 * porque los siete de siempre vienen cargados (paso 6.4). Un tipo propio es
 * para el caso raro y legítimo -"dosis de insulina"- que ninguna lista fija
 * va a cubrir.
 *
 * ## Los dos valores
 *
 * La presión son DOS números (120/80) y es lo que define el esquema: por eso
 * hay `*_secundario`. Un tipo tiene dos valores **si y solo si declara
 * `etiqueta_secundaria`**: es la etiqueta la que decide, porque es lo que el
 * formulario necesita para poder rotular el segundo campo. Sin rótulo no hay
 * forma honesta de pedirlo.
 *
 * `etiqueta_principal` NO está en el plan original y se suma acá: para un
 * tipo de dos valores, tener solo la etiqueta del segundo deja el formulario
 * pidiendo "Valor" y "Diastólica", que es exactamente la asimetría que
 * confunde. Con las dos, pide "Sistólica" y "Diastólica".
 *
 * ## Los rangos de referencia
 *
 * `min_normal`/`max_normal` (y sus pares secundarios, que el plan tampoco
 * tenía: si hay dos valores tiene que haber dos rangos, o el gráfico del
 * paso 6.3 dibuja la banda equivocada justo en el tipo que motivó todo).
 *
 * ⚠️ Son **rango de referencia, no un veredicto**: la regla 1 del proyecto
 * dice que el sistema registra y no aconseja. Se muestran al lado del valor
 * como lo hace un análisis de laboratorio; ninguna pantalla pinta un número
 * de rojo ni dice si está "mal". Quedan **en claro** a propósito: no son un
 * dato clínico de nadie -son una propiedad del tipo, que además puede ser
 * una semilla compartida por todos-.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_medicion', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('usuario_id')->nullable()->constrained('users')->cascadeOnDelete();

            $tabla->text('nombre');
            $tabla->char('nombre_hash', 64);

            $tabla->text('unidad');

            // Solo si difiere de la principal. En presión las dos son mmHg,
            // así que queda null y se muestra `unidad` para los dos números.
            $tabla->text('unidad_secundaria')->nullable();

            $tabla->text('etiqueta_principal')->nullable();
            $tabla->text('etiqueta_secundaria')->nullable();

            $tabla->decimal('min_normal', 8, 2)->nullable();
            $tabla->decimal('max_normal', 8, 2)->nullable();
            $tabla->decimal('min_normal_secundario', 8, 2)->nullable();
            $tabla->decimal('max_normal_secundario', 8, 2)->nullable();

            // Cuántos decimales tiene sentido mostrar: peso 1 (72,5),
            // presión 0 (120), temperatura 1 (36,8). Es formato, no dato.
            $tabla->unsignedTinyInteger('decimales')->default(0);

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index('usuario_id');
            $tabla->unique(['usuario_id', 'nombre_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_medicion');
    }
};
