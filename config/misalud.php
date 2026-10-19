<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Decisiones propias de MiSalud
|--------------------------------------------------------------------------
|
| Solo lo que de verdad cambia entre instalaciones va por `env()`. El resto
| son decisiones del proyecto -explicadas en CLAUDE.md- y viven acá para que
| se puedan leer juntas, no para que se las toque en cada deploy.
|
*/

return [

    /*
     * El buzón único de la app: la persona le reenvía la receta, la orden o el
     * estudio desde su propio correo, y `misalud:procesar-buzon` lo guarda en
     * la ficha que corresponde.
     *
     * ⚠️ La contraseña va SOLO en el `.env` del server: el repo es público. El
     * host y el puerto son los de Hostinger; 993 es IMAP sobre TLS, y no hay
     * forma de pedir una conexión sin cifrar -por ahí viaja la contraseña-.
     */
    'buzon' => [
        'direccion' => env('BUZON_DIRECCION'),
        'password' => env('BUZON_PASSWORD'),
        'host' => env('BUZON_HOST', 'imap.hostinger.com'),
        'puerto' => (int) env('BUZON_PUERTO', 993),
        'carpeta' => 'INBOX',

        // Adonde se mueve un mail ya procesado, para que el INBOX quede con lo
        // nuevo. Si el servidor no deja moverlo, `mails_recibidos` igual evita
        // procesarlo dos veces.
        'carpeta_procesados' => 'Procesados',

        /*
         * Tope de mensajes por corrida: cada uno se trae con sus adjuntos en
         * memoria, y el `memory_limit` de un hosting compartido no es generoso.
         * Lo que no entra se procesa en la corrida siguiente (cinco minutos).
         */
        'tope_por_corrida' => 30,

        /*
         * Cuántos mails se aceptan de un mismo remitente por día. Lo que pasa
         * de ahí se descarta sin contestar: nadie puede llenar una ficha (ni la
         * casilla de alguien con respuestas) a fuerza de mails.
         */
        'tope_por_remitente_por_dia' => 20,
    ],

    /*
     * Cuántos días vale una receta de medicamentos que llega por mail. El
     * mismo default que el formulario: después se edita receta por receta.
     */
    'recetas' => [
        'vigencia_dias' => 30,
    ],

];
