<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las claves de los sitios de salud de un paciente: el portal de la obra
 * social, el del laboratorio, la app de turnos.
 *
 * Las cuatro columnas van cifradas, así que son `text` y sin índice. Sin
 * índice ciego ni UNIQUE: dos cuentas en el mismo sitio son un caso real, y
 * nada busca por estos campos.
 *
 * ⚠️ Sin soft deletes, igual que la casilla: acá viven contraseñas, y con
 * `deleted_at` borrar una la dejaría guardada para siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claves', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();

            $tabla->text('sitio');
            $tabla->text('usuario')->nullable();
            $tabla->text('clave');
            $tabla->text('notas')->nullable();

            $tabla->timestamps();

            $tabla->index('paciente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claves');
    }
};
