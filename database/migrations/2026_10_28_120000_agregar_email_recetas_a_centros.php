<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El correo al que un centro quiere recibir recetas, órdenes y estudios, aparte
 * del general (pedido del usuario). Opcional: sin él, los envíos van al
 * general. Cifrado como el resto del catálogo (`text`, sin índice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('centros', function (Blueprint $tabla): void {
            $tabla->text('email_recetas')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('centros', function (Blueprint $tabla): void {
            $tabla->dropColumn('email_recetas');
        });
    }
};
