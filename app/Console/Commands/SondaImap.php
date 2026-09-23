<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * ¿Puede esta máquina hablar IMAP con la casilla?
 *
 * Existe por dos motivos. El primero fue de planificación: el módulo de recetas
 * depende de que el hosting deje salir al puerto 993, y muchos hostings
 * compartidos bloquean todo lo saliente menos 80/443/587. Averiguarlo antes de
 * escribir el módulo, y no después, es la diferencia entre media hora y una
 * semana.
 *
 * El segundo es de diagnóstico: cuando la sincronización deje de andar, lo
 * primero es saber si el problema es la red, las credenciales o el código. Esto
 * separa el primer caso de los otros dos sin usar ninguna credencial.
 */
class SondaImap extends Command
{
    protected $signature = 'misalud:sonda-imap
        {--host=imap.gmail.com : Servidor IMAP a probar}
        {--puerto=993 : Puerto (993 = IMAP sobre TLS)}
        {--espera=10 : Segundos de espera antes de darlo por bloqueado}';

    protected $description = 'Verifica que se pueda abrir una conexión IMAP saliente, sin usar credenciales';

    /**
     * Lo que necesita `webklex/php-imap`.
     *
     * Ojo: `imap` **no** está en la lista. PHP 8.4 la sacó del core, y el
     * paquete no la usa: habla el protocolo por sockets.
     *
     * @var list<string>
     */
    private const EXTENSIONES = ['openssl', 'mbstring', 'iconv', 'libxml', 'zip', 'fileinfo', 'json'];

    public function handle(): int
    {
        $host = (string) $this->option('host');
        $puerto = (int) $this->option('puerto');
        $espera = (int) $this->option('espera');

        $this->components->info('PHP '.PHP_VERSION.' — '.PHP_BINARY);

        $faltan = array_values(array_filter(
            self::EXTENSIONES,
            static fn (string $ext): bool => ! extension_loaded($ext),
        ));

        foreach (self::EXTENSIONES as $ext) {
            $this->components->twoColumnDetail($ext, extension_loaded($ext) ? '<fg=green>ok</>' : '<fg=red>falta</>');
        }

        // 993 lleva TLS desde el saludo; 143 es texto plano.
        $uri = $puerto === 993 ? "ssl://{$host}:{$puerto}" : "tcp://{$host}:{$puerto}";

        $errno = 0;
        $error = '';
        $inicio = microtime(true);

        $socket = @stream_socket_client($uri, $errno, $error, $espera);
        $ms = (int) round((microtime(true) - $inicio) * 1000);

        if ($socket === false) {
            $this->components->error(sprintf(
                '%s no responde tras %d ms (%s). Si el error es "Connection timed out", el puerto '
                .'saliente está bloqueado y la sincronización de recetas no va a funcionar desde acá.',
                $uri,
                $ms,
                $error !== '' ? $error : "errno {$errno}",
            ));

            return self::FAILURE;
        }

        stream_set_timeout($socket, $espera);
        $saludo = trim((string) fgets($socket, 512));
        fclose($socket);

        $this->components->twoColumnDetail($uri, "<fg=green>abierto</> ({$ms} ms)");
        $this->components->twoColumnDetail('saludo del servidor', $saludo === '' ? '(vacío)' : $saludo);

        if ($faltan !== []) {
            $this->components->warn('Falta(n) la(s) extensión(es): '.implode(', ', $faltan));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
