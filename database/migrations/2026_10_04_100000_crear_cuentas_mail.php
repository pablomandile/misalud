<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La casilla de correo de la que se importan las recetas.
 *
 * ## Cuelga del USUARIO, igual que un catálogo
 *
 * No de un paciente: la casilla es una sola y de ahí salen las recetas de
 * toda la familia que uno administra. Cuál receta es de quién se decide al
 * importarla (`recetas.paciente_id`, paso 12.2), no al configurar la casilla.
 *
 * ## ⚠️ SIN soft deletes, y es una decisión de seguridad
 *
 * Es la primera tabla del proyecto que se aparta de "soft deletes en las
 * entidades principales", y el motivo no es la prolijidad: acá vive una
 * **contraseña**. Con `deleted_at`, borrar la casilla dejaría la credencial
 * guardada en la base para siempre, que es exactamente lo contrario de lo que
 * espera alguien que la da de baja. Borrar tiene que borrar.
 *
 * Las recetas ya importadas no se van con ella: son documentos de la persona
 * y sobreviven por su cuenta (la FK del paso 12.2 va con `nullOnDelete`).
 *
 * ## Qué se cifra y qué queda en claro
 *
 * `direccion`, `password` y `filtros` son las credenciales y los criterios de
 * la casilla: van cifrados como cualquier contenido sensible. `host`, `puerto`
 * y `carpeta` quedan en claro — `imap.gmail.com` no dice nada de nadie, y son
 * los datos con los que hay que **conectarse**, así que cifrarlos solo
 * agregaría un descifrado por sincronización sin proteger nada.
 *
 * ## ⚠️ `direccion`, no `usuario` como decía el plan
 *
 * El plan llamaba `usuario` a esta columna, y no se puede: el modelo ya tiene
 * una relación `usuario()` hacia su dueño —el nombre que usan los catálogos
 * para lo mismo—, y en Eloquent **un atributo con el nombre de una relación la
 * tapa**. `$cuenta->usuario` devolvería el string del login en vez del `User`,
 * sin ningún error. Renombrar la columna es más seguro que renombrar la
 * relación, que es una convención que ya se repite en cinco catálogos.
 *
 * `direccion` además es lo que la pantalla necesita decir ("dirección de
 * correo"): en Gmail, Outlook y cualquier casilla de hosting el usuario de
 * IMAP **es** la dirección.
 *
 * ## No hay columna de encriptación: sale del puerto
 *
 * 993 es IMAP sobre TLS y 143 es STARTTLS, y esos son los dos puertos que la
 * validación acepta. Un desplegable de "método de encriptación" en una app
 * que usan personas mayores es una pregunta que nadie puede contestar, y la
 * respuesta se deduce sin ambigüedad del puerto que ya hay que pedir.
 *
 * ## No hay columna de "última sincronización" todavía
 *
 * La línea que separa este paso del 12.2: **acá va la configuración, no el
 * estado**. `sincronizado_hasta` lo escribe el comando de sincronización, que
 * todavía no existe; crearla ahora sería una columna que ninguna línea de
 * código escribe y que la pantalla mostraría siempre en "nunca". Mismo
 * criterio que `ordenes_estudio.estudio_id` en el paso 9.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_mail', function (Blueprint $tabla): void {
            $tabla->id();

            /*
             * NO fillable: lo pone el controlador desde la sesión, igual que
             * en los catálogos. Y `cascadeOnDelete` porque esto es
             * configuración de la cuenta, no historia clínica: si la cuenta
             * se va, sus credenciales se van con ella.
             */
            $tabla->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();

            $tabla->string('host');
            $tabla->unsignedSmallInteger('puerto')->default(993);

            $tabla->text('direccion');
            $tabla->char('direccion_hash', 64);

            $tabla->text('password');

            // En claro: es la carpeta que hay que abrir, y la prueba de
            // conexión la usa para avisar si no existe.
            $tabla->string('carpeta')->default('INBOX');

            // Cifrado, con un JSON adentro (`encrypted:array`): son las
            // direcciones de las que se importa, y una lista de remitentes
            // dice bastante sobre una persona.
            $tabla->text('filtros')->nullable();

            $tabla->timestamps();

            /*
             * Una dirección, una sola vez por usuario. Configurarla dos veces
             * no duplicaría recetas -de eso se encarga el UNIQUE de
             * `recetas.message_id_hash`- pero sí dejaría dos casillas
             * idénticas sincronizando en paralelo, que es un estado confuso
             * sin ninguna utilidad. Va sobre el HASH y no sobre `direccion`,
             * que es cifrada: el UNIQUE sobre el ciphertext no detectaría
             * nada (ver `ConsultaVigilada`).
             *
             * El host NO entra: la misma dirección servida por dos hosts es
             * la misma casilla.
             */
            $tabla->unique(['usuario_id', 'direccion_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas_mail');
    }
};
