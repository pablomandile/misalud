<?php

declare(strict_types=1);

use App\Enums\TamanioTexto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La preferencia de tamaño de letra vive también en la cuenta, no solo en una
 * cookie: la cookie evita el salto de tamaño al cargar, pero la columna es lo
 * que hace que la preferencia siga a la persona cuando entra desde otro
 * dispositivo. Para un familiar mayor con un teléfono nuevo, esa es la
 * diferencia entre que la app le sirva o no.
 *
 * Es `string` y no un ENUM de MySQL a propósito: sumar un tamaño después sería
 * una migración de cambio de tipo, y el enum de PHP ya valida en la entrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $tabla): void {
            $tabla->string('tamanio_texto', 20)
                ->default(TamanioTexto::porDefecto()->value)
                ->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $tabla): void {
            $tabla->dropColumn('tamanio_texto');
        });
    }
};
