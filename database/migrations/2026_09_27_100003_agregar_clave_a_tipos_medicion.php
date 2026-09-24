<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una identidad estable para las variables que el CÓDIGO tiene que
 * reconocer.
 *
 * El IMC sale de dividir el peso por la altura al cuadrado, así que hay que
 * saber cuál de las variables es "peso" y cuál es "altura". Por el nombre no
 * se puede: está **cifrado** —así que no hay `where nombre = 'Peso'`— y
 * además lo puede editar la persona. Buscarlo por su índice ciego
 * funcionaría hasta que alguien renombre "Peso" a "Peso corporal", y ahí el
 * IMC desaparecería de la pantalla sin que nada lo explique.
 *
 * `clave` la escribe **solo el seeder** —no es fillable y ningún formulario
 * la toca—, así que una variable creada a mano queda con `null` y el código
 * simplemente no la reconoce, que es lo correcto. Al duplicar una semilla la
 * copia se la lleva (`replicate()` copia atributos), y por eso el IMC sigue
 * andando para quien se armó su propia copia de "Peso".
 *
 * Queda **en claro**: no es un dato de nadie, es una etiqueta del sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_medicion', function (Blueprint $tabla): void {
            $tabla->string('clave', 30)->nullable()->after('usuario_id');
        });
    }

    public function down(): void
    {
        Schema::table('tipos_medicion', function (Blueprint $tabla): void {
            $tabla->dropColumn('clave');
        });
    }
};
