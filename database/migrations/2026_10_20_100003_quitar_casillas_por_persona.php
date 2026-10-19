<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sale la casilla que configuraba cada persona: ahora hay un buzón único de la
 * app (ver `config/misalud.php`, `buzon`).
 *
 * ⚠️ **Borra las contraseñas guardadas, y es a propósito.** Sin el módulo que
 * las usaba, son un secreto guardado de más. Las recetas que ya se habían
 * importado se quedan (la bandeja sigue existiendo, fuera del menú); pierden
 * solo el vínculo con la casilla de la que vinieron, que era metadato.
 *
 * El `down()` vuelve a crear la tabla vacía: las contraseñas no vuelven.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recetas', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('cuenta_mail_id');
        });

        Schema::dropIfExists('cuentas_mail');
    }

    public function down(): void
    {
        Schema::create('cuentas_mail', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $tabla->string('host');
            $tabla->unsignedSmallInteger('puerto')->default(993);
            $tabla->text('direccion');
            $tabla->char('direccion_hash', 64);
            $tabla->text('password');
            $tabla->string('carpeta')->default('INBOX');
            $tabla->text('filtros')->nullable();
            $tabla->dateTime('sincronizado_hasta')->nullable();
            $tabla->timestamps();
            $tabla->unique(['usuario_id', 'direccion_hash']);
        });

        Schema::table('recetas', function (Blueprint $tabla): void {
            $tabla->foreignId('cuenta_mail_id')->nullable()->after('usuario_id')
                ->constrained('cuentas_mail')->nullOnDelete();
        });
    }
};
