<?php

declare(strict_types=1);

use App\Models\Cobertura;
use App\Services\ArchivoService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coberturas y alergias dejan de tener papelera: borrar borra (decisión del
 * usuario, ver "Cobertura médica" en CLAUDE.md).
 *
 * Con papelera, una fila borrada seguía ocupando su hash en el UNIQUE por
 * paciente, la validación no la veía, y volver a cargar "OSDE" o "Penicilina"
 * daba un 500. Y no había ninguna pantalla ni ruta para restaurar: lo que
 * estaba en la papelera no lo podía recuperar nadie.
 *
 * Antes de sacar la columna se borra de verdad lo que ya estaba ahí —es
 * justamente lo que trababa la recarga—, y de una cobertura también las fotos
 * de su credencial: primero el disco, después las filas.
 *
 * Sin `down()` que lo devuelva: lo borrado no vuelve, y recrear la columna
 * vacía no restaura nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $archivos = app(ArchivoService::class);

        foreach (Cobertura::query()->whereNotNull('deleted_at')->get() as $cobertura) {
            $archivos->borrarTodosDe($cobertura);
            $cobertura->delete();
        }

        DB::table('alergias')->whereNotNull('deleted_at')->delete();

        Schema::table('coberturas', fn (Blueprint $tabla) => $tabla->dropSoftDeletes());
        Schema::table('alergias', fn (Blueprint $tabla) => $tabla->dropSoftDeletes());
    }

    public function down(): void
    {
        Schema::table('coberturas', fn (Blueprint $tabla) => $tabla->softDeletes());
        Schema::table('alergias', fn (Blueprint $tabla) => $tabla->softDeletes());
    }
};
