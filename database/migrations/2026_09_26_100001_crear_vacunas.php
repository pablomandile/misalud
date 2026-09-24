<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vacunas: el cuarto catálogo. Es el más chico de los cuatro -solo nombre y
 * notas-: el comprobante de una dosis aplicada no cuelga de acá, cuelga de
 * `aplicaciones_vacuna` (Etapa 11), que es el registro clínico real; esto es
 * apenas el nombre de la vacuna, repetido para cada dosis y cada paciente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacunas', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('usuario_id')->nullable()->constrained('users')->cascadeOnDelete();

            $tabla->text('nombre');
            $tabla->char('nombre_hash', 64);

            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index('usuario_id');
            $tabla->unique(['usuario_id', 'nombre_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacunas');
    }
};
