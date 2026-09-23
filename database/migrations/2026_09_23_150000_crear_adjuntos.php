<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los archivos de toda la historia clínica, en una sola tabla polimórfica.
 *
 * Cuelga de todo lo que puede tener un archivo -estudios, resultados, órdenes,
 * recetas, el prospecto de un medicamento, la prescripción de anteojos, la
 * credencial de la obra social, el paciente mismo-, y el `tipo` distingue para
 * qué es. Por eso el módulo va temprano: seis etapas posteriores dependen de él.
 *
 * `tipo` es un `string` y NO un ENUM de MySQL, aunque tenga un enum de PHP
 * detrás. Con un ENUM de base, sumar un caso obliga a acordarse de ensanchar
 * también la columna: los casos de PHP solos pasan los tests -sqlite no valida
 * ENUM- y revientan en producción con un 500 al primer guardado. Es el mismo
 * criterio que `pacientes.sexo`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adjuntos', function (Blueprint $tabla): void {
            $tabla->id();

            /*
             * A quién pertenece el archivo. No lleva `paciente_id`: el paciente
             * se resuelve subiendo por `adjuntable`, y duplicarlo acá abriría
             * la puerta a que las dos referencias digan cosas distintas -y la
             * autorización mire la equivocada-.
             */
            $tabla->morphs('adjuntable');

            $tabla->string('tipo', 40);

            /*
             * Dónde está el archivo en el disco privado. Va EN CLARO: es un
             * nombre aleatorio que no dice nada de nadie, y es por donde hay
             * que encontrarlo. El contenido del archivo sí está cifrado.
             */
            $tabla->string('ruta');

            // El nombre que traía el archivo sí es contenido clínico: un
            // "analisis-juan-perez-marzo.pdf" cuenta bastante.
            $tabla->text('nombre_original');
            $tabla->text('descripcion')->nullable();

            /*
             * En claro, porque sirven para decidir cómo servir y cómo mostrar
             * sin desencriptar nada. `tamanio_bytes` es el del archivo
             * ORIGINAL, no el del cifrado en disco: es el número que la persona
             * reconoce.
             */
            $tabla->string('mime', 120);
            $tabla->unsignedBigInteger('tamanio_bytes');

            /*
             * Solo para audio y video. Se guarda al subir porque calcularlo
             * después obliga a abrir -y desencriptar- cada archivo para poder
             * listar una duración al lado del nombre.
             */
            $tabla->unsignedInteger('duracion_segundos')->nullable();

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index(['adjuntable_type', 'adjuntable_id', 'tipo'], 'adjuntos_adjuntable_tipo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjuntos');
    }
};
