<?php

/*
 * Modo demo: la copia de misalud.pablomandile.com.ar es la demo del portfolio.
 * Con APP_DEMO=true hay una cuenta con una familia inventada que se resiembra
 * todas las noches (`demo:reset`), nadie puede registrarse ni cambiar la cuenta,
 * y no sale ningún mail. En la productiva queda apagado y nada de esto actúa.
 */
return [
    'enabled' => (bool) env('APP_DEMO', false),
    'email' => 'demo@pablomandile.com.ar',
    'password' => 'demo1234',
];
