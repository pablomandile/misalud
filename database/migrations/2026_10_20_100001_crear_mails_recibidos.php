<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué pasó con cada mail que llegó al buzón.
 *
 * Es dos cosas: la deduplicación (`message_id_hash` UNIQUE: un mail no se
 * procesa dos veces aunque el servidor no haya dejado moverlo a Procesados) y
 * el registro de qué se hizo con él.
 *
 * El remitente y el asunto van cifrados, y SOLO para un remitente reconocido:
 * de una dirección desconocida no se guarda nada más que el hash del mensaje.
 * `remitente_hash` es lo que cuenta el tope de mails por remitente por día.
 *
 * Se borran a los 90 días (`misalud:vaciar-papelera`): para entonces el mail ya
 * salió del INBOX y no hay nada que deduplicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mails_recibidos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->char('message_id_hash', 64)->unique();

            $tabla->text('remitente')->nullable();
            $tabla->char('remitente_hash', 64)->nullable()->index();
            $tabla->text('asunto')->nullable();

            $tabla->enum('resultado', [
                'guardado', 'por_revisar', 'desconocido', 'automatico', 'sin_archivos', 'excedido', 'error',
            ]);
            $tabla->foreignId('paciente_id')->nullable()->constrained('pacientes')->nullOnDelete();
            $tabla->dateTime('recibido_en');

            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mails_recibidos');
    }
};
