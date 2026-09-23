<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un paciente es a quien pertenece la historia clínica: el usuario mismo, o un
 * familiar que administra. `usuario_id` es quien lo dio de alta (el
 * propietario original); el acceso real —de él y de quien más lo comparta—
 * vive en `paciente_usuario`.
 *
 * `nombre`, `grupo_sanguineo` y `notas` van cifrados (ver CifraCampos): por
 * eso son `text` y no `varchar`, y por eso no hay UNIQUE ni índice sobre
 * ellos. `fecha_nacimiento` y `sexo` quedan en claro a propósito: la edad se
 * deriva de la fecha en cada request (no se guarda, ver CLAUDE.md), y
 * guardarla cifrada impediría ese cálculo sin desencriptar cada fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacientes', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();

            $tabla->text('nombre');
            $tabla->date('fecha_nacimiento')->nullable();
            $tabla->string('sexo', 20)->nullable();
            $tabla->text('grupo_sanguineo')->nullable();
            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index('usuario_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pacientes');
    }
};
