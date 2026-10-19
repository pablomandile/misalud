<?php

declare(strict_types=1);

use App\Models\Paciente;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El índice ciego del correo del paciente: es por donde el buzón reconoce de
 * quién es la ficha cuando llega un mail. Sobre `email`, que está cifrado, un
 * `where` devolvería cero filas siempre (ver `ConsultaVigilada`).
 *
 * Indexado y SIN UNIQUE: dos fichas de la misma persona -una por cada familiar
 * que la administra- tienen que poder repetir el correo.
 *
 * Rellena las fichas que ya tenían correo con la MISMA función que usa
 * `CifraCampos` en cada guardado (`Paciente::hashCiego()`), así no hay dos
 * cálculos que puedan diferir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pacientes', function (Blueprint $tabla): void {
            $tabla->char('email_hash', 64)->nullable()->after('email');
            $tabla->index('email_hash');
        });

        DB::table('pacientes')
            ->whereNotNull('email')
            ->orderBy('id')
            ->each(function (object $fila): void {
                $email = Crypt::decryptString((string) $fila->email);

                DB::table('pacientes')
                    ->where('id', $fila->id)
                    ->update(['email_hash' => $email === '' ? null : Paciente::hashCiego($email)]);
            });
    }

    public function down(): void
    {
        Schema::table('pacientes', function (Blueprint $tabla): void {
            $tabla->dropIndex(['email_hash']);
            $tabla->dropColumn('email_hash');
        });
    }
};
