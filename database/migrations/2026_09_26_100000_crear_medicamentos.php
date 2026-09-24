<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medicamentos: tercer catálogo, copiado de médicos y centros sin decidir
 * nada nuevo en el esquema (ver CLAUDE.md, sección Catálogos).
 *
 * El nombre visible es `nombre_comercial` y no `nombre` -es lo que trae la
 * caja, no la droga-, así que el modelo pisa `columnaNombre()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicamentos', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('usuario_id')->nullable()->constrained('users')->cascadeOnDelete();

            $tabla->text('nombre_comercial');
            $tabla->char('nombre_hash', 64);

            $tabla->text('droga')->nullable();
            $tabla->text('para_que_sirve')->nullable();
            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index('usuario_id');

            // Mismo UNIQUE que médicos y centros, y la misma salvedad: no
            // protege entre semillas (ver esa migración).
            $tabla->unique(['usuario_id', 'nombre_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicamentos');
    }
};
