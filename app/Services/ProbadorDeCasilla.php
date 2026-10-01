<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoDeConexion;
use App\Models\CuentaMail;
use App\Support\PruebaDeConexion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Folder;

/**
 * ¿Anda esta casilla?
 *
 * Abre una sesión IMAP de verdad con las credenciales guardadas y contesta en
 * cuál de las tres etapas se cayó: la red, el login o la carpeta. El motivo de
 * que distinga las tres está en `EstadoDeConexion`.
 *
 * ## Usa el mismo paquete que va a usar la sincronización
 *
 * Hacer el LOGIN a mano por un socket —como hace `misalud:sonda-imap`— habría
 * sido más corto, y habría sido un test que puede dar **verde mientras la
 * sincronización falla**: `webklex/php-imap` negocia capacidades y elige el
 * método de autenticación por su cuenta, así que un login artesanal exitoso no
 * prueba que el paquete vaya a lograr el suyo. Una prueba de conexión que no
 * ejercita el camino real no sirve para nada.
 *
 * La sonda sigue teniendo su lugar: contesta "¿sale el 993 desde acá?" **sin
 * usar credenciales**, que es la pregunta de antes de tener una casilla.
 *
 * ## ⚠️ La contraseña no puede terminar en un log ni en la pantalla
 *
 * Los mensajes de error de IMAP vienen del servidor y a veces traen partes del
 * comando que los provocó —y el comando que nos interesa es `LOGIN`—. Todo
 * texto que salga de una excepción pasa por `queDijoElServidor()`, que borra la
 * contraseña **antes** de que el string exista para cualquier otro uso: la
 * misma función alimenta el aviso en pantalla y el log, así que no hay un
 * camino donde uno esté saneado y el otro no.
 */
class ProbadorDeCasilla
{
    /**
     * Cuánto se espera antes de darlo por caído.
     *
     * Esto corre dentro de una petición web y alguien está mirando la pantalla,
     * así que no puede ser el default de 30 segundos del paquete: entre el
     * socket y el login, dos esperas de 30 se pasan del tope de ejecución de
     * PHP y la persona ve un error del servidor en vez de la respuesta de la
     * prueba.
     */
    private const SEGUNDOS_DE_ESPERA = 10;

    /** Un mensaje de servidor puede venir enorme, y va a un toast. */
    private const LARGO_DEL_DETALLE = 200;

    public function probar(CuentaMail $cuenta): PruebaDeConexion
    {
        $cliente = $this->cliente($cuenta);

        try {
            $prueba = $this->conectarYRevisar($cliente, $cuenta);
        } finally {
            /*
             * Siempre, incluso cuando el login falló: ahí el socket quedó
             * abierto igual -la conexión anduvo, lo que el servidor rechazó
             * fue el usuario-, y dejarlo colgado ocupa una sesión del lado del
             * servidor hasta que expire.
             */
            $this->cerrar($cliente);
        }

        if (! $prueba->anduvo()) {
            /*
             * Solo ids y el estado. El detalle ya viene sin la contraseña, y
             * la dirección de la casilla no se escribe: misma regla que los
             * errores de Socialite y los de `misalud:enviar-recordatorios`.
             */
            Log::warning('Falló la prueba de una casilla de correo', [
                'cuenta_mail_id' => $cuenta->id,
                'usuario_id' => $cuenta->usuario_id,
                'estado' => $prueba->estado->value,
                'detalle' => $prueba->detalle,
            ]);
        }

        return $prueba;
    }

    /**
     * Las dos etapas, cada una con su propio `catch`.
     *
     * ⚠️ **La excepción de "contraseña equivocada" NO es
     * `AuthFailedException`.** Medido contra `imap.gmail.com` con credenciales
     * inventadas: lo que llega es `ImapServerErrorException` con el mensaje
     * `NO [AUTHENTICATIONFAILED] Invalid credentials (Failure)`.
     * `AuthFailedException` es la que dice el nombre y la que se tira sola a la
     * cara, pero el paquete solo la usa en un camino que un servidor real no
     * toma —`login()` levanta la del `NO` antes—. Atrapar únicamente la
     * "obvia" dejaba un login rechazado cayendo en "error inesperado", que es
     * el peor lugar: el mensaje no nombra la contraseña, que es justo lo que
     * hay que arreglar.
     *
     * ⚠️ **Y por eso los dos `try` están separados, que no es prolijidad.**
     * `ImapServerErrorException` la tira **cualquier** comando que el servidor
     * conteste con `NO`. Acá funciona porque en el primer bloque el único
     * comando que se manda después del saludo es el LOGIN, así que un `NO` ahí
     * **es** un rechazo de credenciales. Con un solo `try` envolviendo todo, un
     * `NO` al abrir la carpeta se leería como "contraseña equivocada" y la
     * persona iría a cambiar lo único que estaba bien.
     */
    private function conectarYRevisar(Client $cliente, CuentaMail $cuenta): PruebaDeConexion
    {
        try {
            $cliente->connect();
        } catch (ConnectionFailedException $e) {
            return PruebaDeConexion::fallo(
                EstadoDeConexion::SinRed,
                $this->queDijoElServidor($e, $cuenta),
            );
        } catch (ImapServerErrorException|AuthFailedException|ResponseException) {
            /*
             * Sin detalle, a propósito. Lo que contesta un servidor acá no
             * agrega nada a "rechazó la dirección o la contraseña" -medido:
             * "Invalid credentials", en inglés y diciendo lo mismo-, y es
             * justamente el mensaje con más chances de traer el comando LOGIN
             * adentro.
             */
            return PruebaDeConexion::fallo(EstadoDeConexion::CredencialesRechazadas);
        } catch (Throwable $e) {
            return PruebaDeConexion::fallo(
                EstadoDeConexion::ErrorInesperado,
                $this->queDijoElServidor($e, $cuenta),
            );
        }

        try {
            // `soft_fail` en true: que la carpeta no exista es una respuesta
            // de la prueba, no una excepción que haya que traducir.
            if ($cliente->getFolderByPath($cuenta->carpeta, false, true) !== null) {
                return PruebaDeConexion::ok();
            }

            return PruebaDeConexion::fallo(
                EstadoDeConexion::CarpetaInexistente,
                $this->carpetasQueHay($cliente),
            );
        } catch (Throwable $e) {
            return PruebaDeConexion::fallo(
                EstadoDeConexion::ErrorInesperado,
                $this->queDijoElServidor($e, $cuenta),
            );
        }
    }

    /**
     * El cliente IMAP, configurado entero acá.
     *
     * ⚠️ **No se resuelve `ClientManager` del contenedor.** El binding que
     * registra `webklex/laravel-imap` hace `new ClientManager(config('imap'))`,
     * y como este proyecto **no publica** `config/imap.php` eso pasa `null` a
     * un parámetro que no lo acepta. Construirlo acá con un array vacío hace
     * que el paquete cargue sus propios defaults, que es lo que queremos: lo
     * que nos importa viaja explícito en `make()`, al lado del comentario que
     * explica cada elección.
     */
    private function cliente(CuentaMail $cuenta): Client
    {
        return (new ClientManager)->make([
            'host' => $cuenta->host,
            'port' => $cuenta->puerto,

            /*
             * `imap` y no `legacy-imap`: el modo legacy usa `ext-imap`, que
             * PHP 8.4 sacó del core y que el server no tiene -lo midió
             * `misalud:sonda-imap`-. Este habla el protocolo por sockets.
             */
            'protocol' => 'imap',
            'encryption' => $cuenta->encriptacion(),

            /*
             * Sin forma de desactivarlo, y sin campo en la pantalla para
             * hacerlo. Un certificado que no valida es la señal de que algo se
             * metió en el medio de una conexión por la que viaja la contraseña
             * de la casilla; la salida correcta es arreglar el servidor.
             */
            'validate_cert' => true,

            'username' => $cuenta->direccion,
            'password' => $cuenta->password,

            // LOGIN y no OAuth: es lo que corresponde a una contraseña de
            // aplicación, que es como se conectan Gmail y el resto.
            'authentication' => null,

            'timeout' => self::SEGUNDOS_DE_ESPERA,
        ]);
    }

    /**
     * Las carpetas que sí existen, para el caso de la carpeta equivocada.
     *
     * Es el detalle que arregla el problema en un intento en vez de en cinco:
     * ver `INBOX.Recetas` en la lista es lo que explica, sin que nadie tenga
     * que saber nada de IMAP, por qué `INBOX/Recetas` no andaba.
     */
    private function carpetasQueHay(Client $cliente): ?string
    {
        try {
            // `hierarchical` en false: la lista plana con la ruta completa de
            // cada una es justo lo que hay que copiar al campo.
            $rutas = $cliente->getFolders(false)
                ->map(static fn (Folder $carpeta): string => $carpeta->path)
                ->all();
        } catch (Throwable) {
            // Si ni siquiera se pueden listar, el mensaje del estado alcanza:
            // no vale convertir esto en otro error y tapar el diagnóstico.
            return null;
        }

        return $rutas === [] ? null : 'Las que hay son: '.implode(', ', $rutas).'.';
    }

    /**
     * El texto de una excepción, sin la contraseña y sin desbordar el aviso.
     */
    private function queDijoElServidor(Throwable $e, CuentaMail $cuenta): ?string
    {
        $mensaje = trim($e->getMessage());

        if ($mensaje === '') {
            return null;
        }

        $password = $cuenta->password;

        if ($password !== '') {
            $mensaje = str_replace($password, '***', $mensaje);
        }

        return 'El servidor dijo: «'.Str::limit($mensaje, self::LARGO_DEL_DETALLE).'».';
    }

    /**
     * Cerrar nunca puede cambiar el resultado de la prueba.
     *
     * Un `logout` que falla después de que todo anduvo convertiría un "está
     * lista" en un error, que es la conclusión opuesta a la verdadera.
     */
    private function cerrar(Client $cliente): void
    {
        try {
            $cliente->disconnect();
        } catch (Throwable) {
            // Nada que hacer ni que informar.
        }
    }
}
