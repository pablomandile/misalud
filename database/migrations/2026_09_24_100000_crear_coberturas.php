<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La cobertura médica de un paciente: obra social, prepaga, mutual o PAMI.
 *
 * Es tabla propia y no columnas en `pacientes` por tres motivos que aparecen
 * enseguida: mucha gente tiene obra social y prepaga A LA VEZ, los planes
 * cambian y la credencial vieja sigue sirviendo para reclamar un reintegro, y
 * cada cobertura tiene su propio número de afiliado. La credencial en sí no
 * vive acá: son dos `adjuntos` tipo `credencial` (frente y dorso), colgados
 * por la relación polimórfica de la Etapa 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coberturas', function (Blueprint $tabla): void {
            $tabla->id();

            // paciente_id NO es fillable (ver CoberturaController): se crea
            // por la relación, como todo registro clínico.
            $tabla->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();

            /*
             * `string` y no un ENUM de MySQL, mismo criterio que
             * `pacientes.sexo` y `adjuntos.tipo`: sumar un caso -mutual,
             * PAMI...- no puede depender de acordarse de ensanchar también la
             * columna, que es el error que sqlite no detecta y MySQL sí, con
             * un 500 al primer guardado en producción.
             */
            $tabla->string('tipo', 20);

            $tabla->text('entidad');
            $tabla->char('entidad_hash', 64);

            $tabla->text('plan')->nullable();
            $tabla->text('nro_afiliado')->nullable();
            $tabla->text('telefono')->nullable();

            // Aparte del teléfono general: es el dato que se busca apurado,
            // y el que casi siempre está en el DORSO de la credencial -la
            // mitad que la gente se olvida de fotografiar-.
            $tabla->text('telefono_urgencias')->nullable();

            $tabla->text('sitio_web')->nullable();

            $tabla->date('vigencia_desde')->nullable();
            $tabla->date('vigencia_hasta')->nullable();

            /*
             * Pueden convivir VARIAS activas: obra social + prepaga es el
             * caso normal, no la excepción. Es la que se ofrece por defecto
             * al cargar un estudio o un turno.
             */
            $tabla->boolean('activa')->default(true);

            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index('paciente_id');

            /*
             * Unique por paciente sobre el índice ciego de la entidad: no
             * puede haber dos coberturas de "OSDE" para el mismo paciente.
             * Sobre `entidad` -cifrada- este UNIQUE no detectaría nada, ver
             * CLAUDE.md.
             */
            $tabla->unique(['paciente_id', 'entidad_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coberturas');
    }
};
