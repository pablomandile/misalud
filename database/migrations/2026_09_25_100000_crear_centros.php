<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centros: segundo catálogo, copiado de `medicos` (ver esa migración para el
 * porqué de cada pieza). Consultorios, clínicas, laboratorios — donde
 * atienden los médicos y donde se hacen los estudios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('centros', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('usuario_id')->nullable()->constrained('users')->cascadeOnDelete();

            $tabla->text('nombre');
            $tabla->char('nombre_hash', 64);

            /*
             * `string` y no un ENUM de MySQL, mismo criterio que
             * `pacientes.sexo` y `coberturas.tipo`: sumar un tipo de centro
             * no puede depender de acordarse de ensanchar también la columna
             * (ver CLAUDE.md).
             */
            $tabla->string('tipo', 30);

            $tabla->text('direccion')->nullable();
            $tabla->text('telefono')->nullable();
            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index('usuario_id');

            // Mismo UNIQUE que medicos, con la misma salvedad: no protege
            // entre semillas (ver esa migración).
            $tabla->unique(['usuario_id', 'nombre_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('centros');
    }
};
