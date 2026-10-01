<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las recetas que llegaron por mail.
 *
 * Una receta **es su archivo**: el PDF que manda la obra social o el médico. La
 * fila de acá es la ficha del mail del que salió —de quién, cuándo, con qué
 * asunto— y el documento cuelga como un `Adjunto` de tipo `Receta`.
 *
 * ## ⚠️ `usuario_id` y NO `paciente_id`
 *
 * El plan ponía `paciente_id`, y no se puede escribir: **un mail de la farmacia
 * no dice de quién es la receta.** Adivinarlo sería cargarle una receta a un
 * familiar equivocado, que es justo el error que el proyecto evita por diseño
 * en mediciones y turnos. Y una `paciente_id` nullable rompería el invariante
 * de `RegistroClinicoPolicy` —"un paciente nulo es NO, nunca «no hay nada que
 * proteger»"—, así que habría que inventar un camino de autorización para un
 * estado que ninguna pantalla puede producir todavía.
 *
 * Así que la receta pertenece a **quien administra la casilla**, que es lo
 * verdadero: el mail llegó a su correo. `usuario_id` no es fillable, por lo
 * mismo que `paciente_id` no lo es en el dominio clínico: de esa FK cuelga toda
 * la autorización.
 *
 * Asignar una receta a un paciente queda pendiente, **y con una pregunta que
 * hay que contestar ahí**: si un cuidador de esa ficha puede verla. Hoy no: la
 * ve quien tiene la casilla.
 *
 * ## `cuenta_mail_id` es metadato, no el dueño
 *
 * Va con `nullOnDelete` siguiendo la regla de la Etapa 7 —bloquear cuando la
 * referencia es imprescindible para leer el registro, dejarla ir cuando es
 * metadato—: una receta se lee perfectamente sin saber de qué casilla vino.
 *
 * Y es lo que cumple la promesa del 12.1: borrar la casilla **no** se lleva las
 * recetas ya importadas, que son documentos de la persona. Si colgaran del
 * `cuenta_mail_id` con `cascade`, dar de baja una casilla vieja borraría recetas
 * que nadie pidió borrar.
 *
 * ## ⚠️ El UNIQUE va por USUARIO, no global como decía el plan
 *
 * El plan pedía `message_id_hash UNIQUE` a secas. Eso tiene un bug concreto: el
 * `Message-ID` es único **del mensaje**, así que si la farmacia le manda el
 * mismo mail a dos personas que las dos usan MiSalud, la segunda no podría
 * importarlo nunca —y el fallo sería silencioso, porque parece "ya estaba"—.
 *
 * Por usuario también es mejor que por casilla: sobrevive a que alguien borre y
 * vuelva a configurar la casilla, y deduplica cuando dos casillas de la misma
 * persona reciben el mismo mail por un alias.
 *
 * ⚠️ Con soft deletes, una receta en la papelera **sigue ocupando su hash**, así
 * que no se reimporta mientras esté ahí. Es lo correcto: todavía existe y se
 * puede restaurar. Para traerla de nuevo hay que borrarla de verdad.
 *
 * ## "Vencida" NO es un estado guardado
 *
 * El plan listaba `vencida` entre los estados. Se deriva de
 * `fecha_recepcion + vigencia_dias`, igual que la edad sale de la fecha de
 * nacimiento (regla 4): guardarla obligaría a un segundo comando del scheduler
 * cuyo único trabajo sería corregir una cuenta que ya se puede hacer sola, y
 * entre que vence y que el comando corre la pantalla mostraría "disponible" una
 * receta que no lo está.
 *
 * ## Qué columnas NO están todavía
 *
 * La regla de este paso: **entra la columna que la importación escribe.**
 * `estado` y `vigencia_dias` los fija el import. `fecha_uso` lo escribe la misma
 * acción que pone `Usada` —marcar una receta como usada, que es del paso 12.3—,
 * así que llega con ella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recetas', function (Blueprint $tabla): void {
            $tabla->id();

            // NO fillable: de esta FK cuelga toda la autorización.
            $tabla->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();

            // Metadato: de qué casilla vino. Borrar la casilla no se lleva la receta.
            $tabla->foreignId('cuenta_mail_id')->nullable()
                ->constrained('cuentas_mail')->nullOnDelete();

            /*
             * El `Message-ID` del mail. Cifrado porque identifica un mensaje de
             * una persona, y acompañado de su hash porque es lo ÚNICO que evita
             * reimportar: sobre la columna cifrada un UNIQUE no detecta nada
             * -el ciphertext cambia en cada guardado-.
             */
            $tabla->text('message_id');
            $tabla->char('message_id_hash', 64);

            $tabla->text('remitente');
            $tabla->text('asunto')->nullable();

            /*
             * En claro, como todas las fechas: es por donde ordena la bandeja y
             * de donde sale el vencimiento. Es la fecha del MENSAJE, no la de la
             * importación -si el servidor estuvo caído tres días, la receta
             * sigue venciendo desde que llegó-.
             */
            $tabla->dateTime('fecha_recepcion');

            $tabla->unsignedSmallInteger('vigencia_dias');

            // En claro: es un estado, no contenido clínico.
            $tabla->string('estado', 20);

            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->unique(['usuario_id', 'message_id_hash']);
            $tabla->index(['usuario_id', 'fecha_recepcion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recetas');
    }
};
