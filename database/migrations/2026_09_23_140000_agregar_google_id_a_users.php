<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            /*
             * El identificador de la cuenta de Google (el `sub` del token).
             *
             * Va EN CLARO y no cifrado, al revés que el contenido clínico: es
             * justamente la columna por la que se busca al volver de Google, y
             * sobre una columna cifrada ese `where` devolvería cero filas
             * siempre (ver CLAUDE.md). No es un dato clínico: es un número
             * opaco que no dice nada de la persona.
             *
             * `unique` con `nullable`: MySQL admite todos los NULL que quiera
             * en un índice único, así que las cuentas sin Google no chocan
             * entre sí.
             */
            $table->string('google_id')->nullable()->unique()->after('email');

            /*
             * La contraseña pasa a ser opcional.
             *
             * Quien entra con Google nunca eligió una. Guardarle una al azar
             * la haría figurar como que puede entrar con email y clave cuando
             * no puede, y dejaría un hash que nadie conoce ocupando el lugar
             * del "todavía no tiene".
             */
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['google_id']);
            $table->dropColumn('google_id');
            $table->string('password')->nullable(false)->change();
        });
    }
};
