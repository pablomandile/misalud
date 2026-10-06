<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que se mandó, a quién y cuándo.
 *
 * Es un **registro de algo que pasó**, y por eso todo lo que importa se guarda
 * como una foto del momento y no como una referencia:
 *
 * - `destinatario` y `destinatario_nombre` copian la dirección y el nombre del
 *   contacto **tal como estaban al mandar**. Si mañana se corrige el mail del
 *   contacto, el historial tiene que seguir diciendo a dónde salió de verdad.
 *   `contacto_id` queda solo como vínculo, con `nullOnDelete`.
 * - Los archivos van en `adjunto_envio` con su **nombre copiado**, por lo mismo:
 *   borrar un documento elimina su archivo del disco (la fila queda en la
 *   papelera, sin nada que abrir), y "se mandó la orden" no puede volverse "se
 *   mandó algo" el día que alguien borra el PDF.
 *
 * **Sin soft deletes y sin ruta para borrar**: el historial es lo que se consulta
 * cuando la obra social dice "no nos llegó nada", y uno que se puede editar no
 * prueba nada.
 *
 * La fecha del envío es `created_at`: se registra en el mismo instante en que se
 * intenta, así que una columna `fecha` aparte —como ponía el plan— solo podría
 * diferir de ella por un error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envios', function (Blueprint $tabla): void {
            $tabla->id();

            // NO fillable: de esta FK cuelga toda la autorización.
            $tabla->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();

            $tabla->foreignId('contacto_id')->nullable()
                ->constrained('contactos')->nullOnDelete();

            $tabla->text('destinatario');
            $tabla->text('destinatario_nombre');
            $tabla->text('asunto');
            $tabla->text('cuerpo')->nullable();

            $tabla->string('estado', 20);

            $tabla->timestamps();

            $tabla->index(['usuario_id', 'created_at']);
        });

        /*
         * Qué archivos salieron en cada envío. Es la "adjunto_envio" del plan, con
         * dos columnas más que un pivote puro: la foto del nombre y del tamaño.
         */
        Schema::create('adjunto_envio', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('envio_id')->constrained('envios')->cascadeOnDelete();

            // `nullOnDelete`: borrar el archivo de verdad (un medicamento que se
            // borra se lleva su prospecto) no borra que se mandó.
            $tabla->foreignId('adjunto_id')->nullable()
                ->constrained('adjuntos')->nullOnDelete();

            $tabla->text('nombre');
            $tabla->unsignedInteger('tamanio_bytes');

            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjunto_envio');
        Schema::dropIfExists('envios');
    }
};
