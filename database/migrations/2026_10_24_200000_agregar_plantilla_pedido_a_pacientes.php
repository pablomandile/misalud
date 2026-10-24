<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El mensaje del último pedido de medicamentos de cada ficha, que se precarga en
 * el siguiente. Cifrado: puede nombrar medicamentos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pacientes', function (Blueprint $tabla): void {
            $tabla->text('plantilla_pedido')->nullable()->after('notas');
        });
    }

    public function down(): void
    {
        Schema::table('pacientes', function (Blueprint $tabla): void {
            $tabla->dropColumn('plantilla_pedido');
        });
    }
};
