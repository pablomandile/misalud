<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La libreta de a quién se le manda documentación: la farmacia, la obra social,
 * el médico, la óptica.
 *
 * ## Cuelga del usuario, pero NO es un catálogo
 *
 * Se parece —es del usuario y sirve para toda la familia— pero el patrón de
 * catálogos indexa el **nombre**, y acá la identidad de un contacto es su
 * **dirección**: "Farmacia Central" puede tener dos sucursales con dos mails
 * distintos, y las dos son contactos legítimos. Y no hay semillas: nadie publica
 * una lista de direcciones compartida entre usuarios. Así que el UNIQUE va por
 * `email_hash` y no por `nombre_hash`, que es lo que pedía el plan.
 *
 * ## ⚠️ El destino de un envío es SIEMPRE un contacto de acá
 *
 * Es la decisión que justifica la tabla. Mandar un documento clínico a una
 * dirección tipeada en el momento convierte un error de tipeo en una historia
 * clínica en la bandeja de un desconocido, sin forma de deshacerlo. En la libreta
 * la dirección se escribió una vez, con calma, y en el envío se elige por nombre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contactos', function (Blueprint $tabla): void {
            $tabla->id();

            // NO fillable: de esta FK cuelga toda la autorización.
            $tabla->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();

            $tabla->text('nombre');

            // Cifrado: la dirección de un tercero (el mail de un médico, por
            // ejemplo) es un dato personal, y su hash es lo que permite el UNIQUE.
            $tabla->text('email');
            $tabla->char('email_hash', 64);

            // En claro: agrupa la libreta y no dice nada de nadie.
            $tabla->string('tipo', 20);

            $tabla->timestamps();

            $tabla->unique(['usuario_id', 'email_hash']);
            $tabla->index('usuario_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contactos');
    }
};
