<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * La constancia de qué versión de los términos y la privacidad aceptó cada
 * cuenta, y cuándo. Nullable: las cuentas de antes no aceptaron nada, y el
 * middleware `AceptoTerminos` se las pide en su próxima visita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('terminos_version', 20)->nullable();
            $table->timestamp('terminos_aceptados_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['terminos_version', 'terminos_aceptados_en']);
        });
    }
};
