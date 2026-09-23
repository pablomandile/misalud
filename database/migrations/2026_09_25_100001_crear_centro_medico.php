<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dónde atiende cada médico. Ninguna de las dos columnas es del dominio
 * clínico -son dos catálogos del usuario apuntándose entre sí-, así que no
 * hace falta `usuario_id` propio: la autorización de vincular pasa por poder
 * VER los dos lados (ver `CentroGuardarRequest`), no por un dueño del pivote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('centro_medico', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('centro_id')->constrained('centros')->cascadeOnDelete();
            $tabla->foreignId('medico_id')->constrained('medicos')->cascadeOnDelete();
            $tabla->timestamps();

            $tabla->unique(['centro_id', 'medico_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('centro_medico');
    }
};
