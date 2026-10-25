<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El mensaje del pedido pasa de la ficha a cada medicamento: cada uno se pide
 * con su propio texto, y ese texto vuelve precargado en el próximo pedido.
 * Cifrado, como el resto del tratamiento.
 *
 * La plantilla general de la ficha no se copia: decía «estos medicamentos» y no
 * sirve como mensaje de uno solo. Cada medicamento arranca con el texto por defecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tratamientos', function (Blueprint $tabla): void {
            $tabla->text('plantilla_pedido')->nullable()->after('notas');
        });

        Schema::table('pacientes', function (Blueprint $tabla): void {
            $tabla->dropColumn('plantilla_pedido');
        });
    }

    public function down(): void
    {
        Schema::table('pacientes', function (Blueprint $tabla): void {
            $tabla->text('plantilla_pedido')->nullable()->after('notas');
        });

        Schema::table('tratamientos', function (Blueprint $tabla): void {
            $tabla->dropColumn('plantilla_pedido');
        });
    }
};
