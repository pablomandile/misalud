<?php

return [

    /*
     * Versión vigente de los términos y la política de privacidad, juntos.
     *
     * Subirla vuelve a pedir la aceptación a todos en su próxima visita: es lo
     * que hay que hacer cada vez que cambia algo importante de los textos de
     * `resources/legales/`. Un cambio de redacción que no cambia nada no la sube.
     *
     * ⚠️ En producción la config está cacheada: después de cambiarla hay que
     * correr `config:cache`, o el middleware sigue comparando contra la vieja.
     */
    'version' => '2026-10-15',

];
