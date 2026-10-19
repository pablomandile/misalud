<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El nombre que le pone la persona a un documento ("Análisis de marzo"), aparte
 * del nombre del archivo, que lo eligió el celular o quien lo mandó
 * ("IMG_2031.jpg"). Opcional: sin él se muestra el del archivo.
 *
 * Cifrado como `nombre_original` y `descripcion`: dice de qué se trata el
 * documento, y eso es contenido clínico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adjuntos', function (Blueprint $tabla): void {
            $tabla->text('titulo')->nullable()->after('nombre_original');
        });
    }

    public function down(): void
    {
        Schema::table('adjuntos', function (Blueprint $tabla): void {
            $tabla->dropColumn('titulo');
        });
    }
};
