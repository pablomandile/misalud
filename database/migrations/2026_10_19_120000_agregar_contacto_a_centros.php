<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp, horarios, correo y web de un centro. Cifrados como el resto del
 * catálogo (`text`, sin índice): nada busca por ellos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('centros', function (Blueprint $tabla): void {
            $tabla->text('whatsapp')->nullable()->after('telefono');
            $tabla->text('email')->nullable()->after('whatsapp');
            $tabla->text('web')->nullable()->after('email');
            $tabla->text('horarios')->nullable()->after('web');
        });
    }

    public function down(): void
    {
        Schema::table('centros', function (Blueprint $tabla): void {
            $tabla->dropColumn(['whatsapp', 'email', 'web', 'horarios']);
        });
    }
};
