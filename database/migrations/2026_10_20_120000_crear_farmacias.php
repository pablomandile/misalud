<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las farmacias: el sexto catálogo, con el mismo patrón que los otros cinco.
 *
 * Cuelga del USUARIO y no del paciente: la farmacia del barrio es la de toda
 * la familia. Todo cifrado y `text`; el `UNIQUE(usuario_id, nombre_hash)` frena
 * cargar dos veces la misma. Sin semillas: nadie publica una lista de farmacias
 * compartida entre usuarios, igual que con médicos y centros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farmacias', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('usuario_id')->nullable()->constrained('users')->cascadeOnDelete();

            $tabla->text('nombre');
            $tabla->char('nombre_hash', 64);
            $tabla->text('direccion')->nullable();
            $tabla->text('telefono')->nullable();
            $tabla->text('whatsapp')->nullable();
            $tabla->text('email')->nullable();
            $tabla->text('web')->nullable();
            $tabla->text('horarios')->nullable();
            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->unique(['usuario_id', 'nombre_hash']);
            $tabla->index('usuario_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farmacias');
    }
};
