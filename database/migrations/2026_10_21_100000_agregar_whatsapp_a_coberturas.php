<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El WhatsApp de la obra social o la prepaga, cifrado y `text` como el resto de
 * la cobertura. La web (`sitio_web`) ya existía y ningún formulario la pedía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coberturas', function (Blueprint $tabla): void {
            $tabla->text('whatsapp')->nullable()->after('telefono_urgencias');
        });
    }

    public function down(): void
    {
        Schema::table('coberturas', function (Blueprint $tabla): void {
            $tabla->dropColumn('whatsapp');
        });
    }
};
