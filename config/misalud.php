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

    'recetas' => [

        /*
         * Primera corrida: cuántos días hacia atrás mirar.
         *
         * Va por `env()` porque es lo único que depende de la casilla de cada
         * uno: quien recién la conecta quiere traer lo del último par de meses,
         * y quien la reconecta después de un año capaz quiere más.
         */
        'dias_iniciales' => (int) env('RECETAS_DIAS_INICIALES', 60),

        /*
         * Cuánto se vuelve a mirar hacia atrás en cada corrida.
         *
         * No es paranoia: el `SINCE` de IMAP compara contra la fecha del
         * servidor **con granularidad de día**, y un mail puede entregarse
         * tarde o aparecer fuera de orden. Reimportar es gratis -el UNIQUE de
         * `message_id_hash` lo frena-, así que el solapamiento solo cuesta unas
         * cabeceras de más y evita el agujero.
         */
        'dias_de_solapamiento' => 7,

        /*
         * Cuántos días vale una receta desde que llegó.
         *
         * Es el default de `recetas.vigencia_dias`, que después se puede editar
         * receta por receta: las de una obra social valen un mes, pero hay
         * crónicas que valen tres.
         */
        'vigencia_dias' => 30,

        /*
         * Tope de mensajes por corrida.
         *
         * Cada mensaje se trae con su cuerpo y sus adjuntos en memoria, y el
         * `memory_limit` de un hosting compartido no es generoso: una casilla
         * sin filtros apuntada a la bandeja de entrada puede tener miles de
         * mails en la ventana inicial. Con el tope, esa casilla avanza de a
         * tandas -ver `SincronizadorDeRecetas`, que mueve la marca a la fecha
         * del mensaje más nuevo que procesó- en vez de morirse por tiempo de
         * ejecución y no terminar nunca.
         */
        'tope_por_corrida' => 50,
    ],

];
