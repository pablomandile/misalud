<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada toma: un peso, una presión, una glucemia.
 *
 * ## `fecha` es `datetime` y queda EN CLARO
 *
 * `datetime` y no `date` porque en la mitad de las variables la hora **es**
 * el dato: una presión de la mañana y una de la noche no son comparables, y
 * una glucemia en ayunas tampoco es lo mismo que una después de comer.
 * Guardarlas solo con el día las volvería dos puntos indistinguibles.
 *
 * En claro, como todas las fechas del proyecto: es la columna por la que
 * ordena la línea de tiempo y por la que va a paginar el gráfico del paso
 * 6.3. Cifrarla convertiría cada listado en "traer todo y ordenar en PHP".
 *
 * ## `valor` va cifrado, y eso lo vuelve TEXTO
 *
 * Es contenido clínico, así que va con el cast `encrypted` — y de ahí sale
 * la consecuencia que hay que tener presente: **una columna cifrada es
 * `text`, y lo que vuelve de ella es un string**, no un número. No se puede
 * ordenar, promediar ni filtrar por valor en SQL (`AVG()`, `WHERE valor >
 * 120` y un índice son todos imposibles acá). El modelo expone accesores
 * numéricos para que nadie compare strings sin darse cuenta.
 *
 * ## El índice
 *
 * `(paciente_id, tipo_medicion_id, fecha)` es exactamente la consulta del
 * gráfico y del listado: "todo el peso de esta persona, en orden".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mediciones', function (Blueprint $tabla): void {
            $tabla->id();

            // NO fillable en el modelo: de esta FK cuelga toda la
            // autorización. Se crea por la relación del paciente.
            $tabla->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();

            /*
             * `restrictOnDelete` y no `cascade` ni `nullOnDelete`, que son
             * las dos formas de perder datos clínicos por una operación de
             * catálogo: con cascade, borrar el tipo "Peso" se llevaría
             * doscientos pesos cargados; con nullOnDelete quedarían
             * doscientos números sin unidad ni nombre, que es peor que
             * borrarlos porque parecen datos. El controlador además lo frena
             * antes, con un mensaje que se entiende; esto es la última línea.
             */
            $tabla->foreignId('tipo_medicion_id')->constrained('tipos_medicion')->restrictOnDelete();

            $tabla->dateTime('fecha');

            $tabla->text('valor');
            $tabla->text('valor_secundario')->nullable();
            $tabla->text('notas')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index(['paciente_id', 'tipo_medicion_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mediciones');
    }
};
