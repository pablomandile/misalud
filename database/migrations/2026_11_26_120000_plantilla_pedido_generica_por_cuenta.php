<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El mensaje genérico del pedido de medicamentos: el que usa un medicamento que
 * no tiene plantilla propia. Uno por CUENTA (decisión del usuario), porque el mail
 * sale a nombre de quien lo manda. Cifrado (`text`); NULL = el texto original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $tabla): void {
            $tabla->text('plantilla_pedido')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $tabla): void {
            $tabla->dropColumn('plantilla_pedido');
        });
    }
};
