<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DNI, teléfono y correo del paciente. Los tres van cifrados (ver
 * CifraCampos): por eso son `text` y sin índice. No llevan índice ciego ni
 * UNIQUE porque nada busca por ellos, y dos fichas de la misma persona —una
 * por cada familiar que la administra— tienen que poder repetir el DNI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pacientes', function (Blueprint $tabla): void {
            $tabla->text('dni')->nullable()->after('nombre');
            $tabla->text('telefono')->nullable()->after('grupo_sanguineo');
            $tabla->text('email')->nullable()->after('telefono');
        });
    }

    public function down(): void
    {
        Schema::table('pacientes', function (Blueprint $tabla): void {
            $tabla->dropColumn(['dni', 'telefono', 'email']);
        });
    }
};
