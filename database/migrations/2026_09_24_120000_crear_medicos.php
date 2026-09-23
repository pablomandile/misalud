<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Médicos: el PRIMER catálogo, y por lo tanto la plantilla de los otros tres
 * (centros, medicamentos, vacunas) y de `tipos_medicion` en la Etapa 6.
 *
 * Un catálogo se distingue del resto del dominio en una cosa: **cuelga del
 * USUARIO, no del paciente**. Un médico es el mismo para toda la familia que
 * uno administra; duplicarlo por paciente sería cargar tres veces el mismo
 * teléfono y que al cambiar de número queden dos desactualizados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicos', function (Blueprint $tabla): void {
            $tabla->id();

            /*
             * NULLABLE a propósito: `usuario_id` NULL significa **semilla
             * compartida** (regla 5 de CLAUDE.md). Nadie la edita; quien
             * quiera cambiarla la duplica a su propio catálogo.
             *
             * Para médicos no va a haber semillas -nadie publica una lista de
             * médicos-, pero la columna se define igual desde el primer
             * catálogo: es la plantilla, y `tipos_medicion` (Etapa 6) sí las
             * necesita.
             */
            $tabla->foreignId('usuario_id')->nullable()->constrained('users')->cascadeOnDelete();

            $tabla->text('nombre');
            $tabla->char('nombre_hash', 64);

            $tabla->text('especialidad')->nullable();
            $tabla->text('telefono')->nullable();
            $tabla->text('email')->nullable();
            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index('usuario_id');

            /*
             * Unique por el índice ciego y acotado al usuario: no puede haber
             * dos "Dr. Pérez" en MI catálogo, pero sí uno en el mío y otro en
             * el de otra persona. Sobre `nombre` -cifrado- este UNIQUE no
             * detectaría nada (ver CLAUDE.md).
             *
             * ⚠️ NO protege a las semillas: MySQL admite todos los NULL que
             * quiera en un índice único, así que dos filas (NULL, mismo hash)
             * entran sin chistar. Por eso el seeder de semillas tiene que ser
             * idempotente por su cuenta (paso 5.4), sin apoyarse en esto.
             */
            $tabla->unique(['usuario_id', 'nombre_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicos');
    }
};
