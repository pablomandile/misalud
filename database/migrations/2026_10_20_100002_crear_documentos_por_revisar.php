<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que llegó al buzón de un remitente conocido pero no se pudo guardar solo:
 * el asunto no dice si es receta, orden o estudio; no se sabe de cuál de sus
 * fichas es; o el servidor del remitente no confirmó que el mail sea suyo.
 *
 * Lo ve quien puede editar `paciente_id` (si se sabe la ficha) y la cuenta
 * `usuario_id` (si el mail vino de su correo). Al menos uno de los dos: un
 * documento sin dueño posible no se guarda.
 *
 * Sin soft deletes: resolverlo lo convierte en una receta, una orden o un
 * estudio, y descartarlo lo borra con sus archivos. No hay nada que restaurar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_por_revisar', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('paciente_id')->nullable()->constrained('pacientes')->cascadeOnDelete();
            $tabla->foreignId('usuario_id')->nullable()->constrained('users')->cascadeOnDelete();

            $tabla->text('remitente');
            $tabla->text('asunto')->nullable();
            $tabla->enum('motivo', ['sin_tipo', 'paciente_ambiguo', 'sin_verificar']);
            $tabla->enum('tipo_sugerido', ['receta', 'orden', 'estudio'])->nullable();
            $tabla->dateTime('fecha_recepcion');

            $tabla->timestamps();

            $tabla->index('paciente_id');
            $tabla->index('usuario_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_por_revisar');
    }
};
