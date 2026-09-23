<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * El mismo modelo de juguete, pero contra MySQL.
 *
 * Los tests corren sobre sqlite en memoria, que es rápido y suficiente para la
 * lógica. Pero sqlite es permisivo con cosas que MySQL rechaza —longitudes de
 * columna, ENUM, colaciones—, así que el cifrado se verifica además contra el
 * motor real.
 */
class ModeloCifradoMysql extends ModeloCifrado
{
    protected $connection = 'mysql';

    protected $table = 'sonda_cifrado_mysql';
}
