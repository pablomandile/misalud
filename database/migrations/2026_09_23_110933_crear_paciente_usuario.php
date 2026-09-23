<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El pivote es la ÚNICA fuente de verdad para "¿quién puede ver/editar a este
 * paciente?". Ninguna Policy del dominio clínico compara `usuario_id` a mano:
 * todas preguntan por acá. Es lo que permite compartir una ficha (Etapa 14)
 * sin reescribir ninguna Policy — un family member nuevo es una fila más.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paciente_usuario', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();
            $tabla->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $tabla->string('rol', 20);
            $tabla->timestamps();

            $tabla->unique(['paciente_id', 'usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paciente_usuario');
    }
};
