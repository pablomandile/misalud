<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada parámetro de un estudio: "Glucemia: 90 mg/dl (70 a 110)".
 *
 * ## `valor` es texto, no número -mismo caso que `mediciones.valor`-
 *
 * Un resultado de laboratorio no siempre es un número: "Positivo",
 * "Negativo", "3+" son resultados válidos. Por eso `valor` es texto cifrado
 * y el modelo expone `valorNumerico()` para cuando sí lo es -que es lo que
 * necesita el gráfico de evolución del paso 9.4-. Ningún resultado no
 * numérico entra a ese gráfico, y no hace falta una columna aparte para
 * saberlo: si no convierte a float, no se grafica.
 *
 * ## `parametro_hash` no es para unicidad
 *
 * Es el primer índice ciego del proyecto que **no** protege un UNIQUE: dos
 * estudios pueden repetir "Glucemia" sin problema -es lo esperable, un
 * control se repite-. Sirve para **agrupar sin descifrar**: la evolución de
 * un parámetro junta todos los resultados de un paciente que comparten
 * `parametro_hash`, y compara ese string en vez de desencriptar cada fila
 * solo para decidir a qué grupo pertenece.
 *
 * `cascadeOnDelete` sobre `estudio_id`: un resultado no existe sin su
 * estudio, no es un registro que pueda quedar suelto -mismo criterio que
 * `registros_enfermedad.enfermedad_id`-.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resultados_estudio', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('estudio_id')->constrained('estudios')->cascadeOnDelete();

            $tabla->text('parametro');
            $tabla->char('parametro_hash', 64);

            $tabla->text('valor');
            $tabla->text('unidad')->nullable();
            $tabla->text('rango_referencia')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index(['estudio_id', 'parametro_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultados_estudio');
    }
};
