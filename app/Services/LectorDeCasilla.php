<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\AdjuntoDeCasilla;
use App\Support\MensajeDeCasilla;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * Lo único que habla IMAP con el buzón de la app. Traduce mensajes a
 * `MensajeDeCasilla` y los mueve a Procesados, y nada más.
 *
 * ## Es tonto a propósito
 *
 * Acá no hay ninguna regla: ni quién es el remitente, ni qué dice el asunto, ni
 * si el mail es auténtico, ni qué adjunto sirve. Todo eso vive del otro lado
 * del DTO (`ProcesadorDeBuzon`, `CabecerasDelMail`, `AutenticidadDelMail`,
 * `AsuntoDeDocumento`), que **se puede probar entero sin red**. Esta clase es
 * la costura, y es la única que no tiene tests automáticos porque no se puede
 * tener sin un servidor de verdad: por eso conviene que no decida nada. Por eso
 * también pasa los encabezados CRUDOS y no los interpreta.
 *
 * ## El buzón es de la app
 *
 * Al revés que la casilla de cada persona de antes, este buzón es nuestro: se
 * puede mover lo procesado a otra carpeta. Igual se trae con `FT_PEEK` (sin la
 * marca de leído): lo que no se pudo procesar por un error sigue apareciendo
 * como nuevo para quien entre al webmail a mirarlo.
 */
class LectorDeCasilla
{
    /**
     * Corre en el scheduler, sin nadie esperando, pero no puede quedarse
     * colgado: la corrida siguiente arranca en cinco minutos.
     */
    private const SEGUNDOS_DE_ESPERA = 30;

    /**
     * Los mensajes del INBOX, de a lo sumo `$tope`, del más viejo al más nuevo:
     * un mail que llegó primero se procesa primero.
     *
     * @return list<MensajeDeCasilla>
     */
    public function mensajes(int $tope): array
    {
        return $this->conCliente(function (Client $cliente) use ($tope): array {
            $mensajes = $cliente->getFolderByPath($this->carpeta())
                ?->query()
                ->all()
                ->leaveUnread()
                ->setFetchOrder('asc')
                ->limit($tope)
                ->get() ?? [];

            $traducidos = [];

            foreach ($mensajes as $mensaje) {
                $traducidos[] = $this->traducir($mensaje);
            }

            return $traducidos;
        });
    }

    /**
     * Mueve a Procesados los mensajes con estos UID, creando la carpeta si no
     * existe. Lo que no se pueda mover queda en el INBOX: `mails_recibidos` lo
     * frena en la corrida siguiente, así que un fallo acá no reprocesa nada.
     *
     * @param  list<int>  $uids
     */
    public function moverAProcesados(array $uids): void
    {
        if ($uids === []) {
            return;
        }

        /*
         * ⚠️ Un fallo acá NO puede tirar el comando: para cuando se llega a
         * mover, los documentos ya están guardados y la respuesta ya salió.
         * Antes la excepción subía y el comando terminaba en "No se pudo leer
         * el buzón", cada 5 minutos, con todo procesado bien.
         */
        try {
            $this->conCliente(function (Client $cliente) use ($uids): void {
                $carpeta = $cliente->getFolderByPath($this->carpeta());

                if ($carpeta === null) {
                    return;
                }

                $destino = self::rutaDeProcesados(
                    (string) config('misalud.buzon.carpeta_procesados'),
                    $carpeta->path,
                    $carpeta->delimiter,
                    array_values(array_map(fn ($f): string => $f->path, $cliente->getFolders(false)->all())),
                );

                /*
                 * ⚠️ La carpeta recién creada no se ve en la misma conexión:
                 * los `move` de esa corrida fallan (en silencio, abajo) y los
                 * mails se mueven en la siguiente. Medido en Hostinger. No se
                 * pierde nada: `mails_recibidos` frena que se reprocesen.
                 */
                if ($cliente->getFolderByPath($destino) === null) {
                    $cliente->createFolder($destino);
                }

                foreach ($uids as $uid) {
                    try {
                        $carpeta->query()->getMessageByUid($uid)->move($destino);
                    } catch (\Throwable) {
                        // Ver el comentario del método: no mover no cambia el resultado.
                    }
                }
            });
        } catch (\Throwable $error) {
            // Al log la clase y nada más: el mensaje puede venir del servidor IMAP.
            Log::warning('No se pudieron mover los mails del buzón a Procesados.', ['excepcion' => $error::class]);
        }
    }

    /**
     * Dónde va la carpeta de procesados.
     *
     * ⚠️ En Hostinger (Dovecot) todas las carpetas cuelgan del INBOX con `.`
     * como delimitador (`INBOX.Sent`, `INBOX.Trash`) y crear `Procesados` en la
     * raíz lo rechaza el servidor. Medido contra el buzón real. Se usa la que
     * ya exista, en la raíz o adentro del INBOX; si no hay ninguna, se crea
     * adentro del INBOX, que es donde el servidor deja crear.
     *
     * @param  list<string>  $existentes
     */
    public static function rutaDeProcesados(string $nombre, string $inbox, string $delimitador, array $existentes): string
    {
        if (in_array($nombre, $existentes, true)) {
            return $nombre;
        }

        return $inbox.$delimitador.$nombre;
    }

    /**
     * @template T
     *
     * @param  callable(Client): T  $trabajo
     * @return T
     */
    private function conCliente(callable $trabajo): mixed
    {
        $cliente = $this->cliente();

        try {
            $cliente->connect();

            return $trabajo($cliente);
        } finally {
            try {
                $cliente->disconnect();
            } catch (\Throwable) {
                // Cerrar nunca puede cambiar el resultado.
            }
        }
    }

    private function traducir(Message $mensaje): MensajeDeCasilla
    {
        $adjuntos = [];

        foreach ($mensaje->getAttachments() as $adjunto) {
            if (! $this->esArchivoDeVerdad($adjunto)) {
                continue;
            }

            $contenido = $adjunto->content;

            if (! is_string($contenido) || $contenido === '') {
                continue;
            }

            $adjuntos[] = new AdjuntoDeCasilla(
                nombre: (string) ($adjunto->name ?? 'adjunto'),
                contenido: $contenido,
            );
        }

        $cruda = $mensaje->date->first();
        $fecha = $cruda instanceof \DateTimeInterface
            ? CarbonImmutable::instance($cruda)
            : CarbonImmutable::now();

        $remitente = $this->remitenteDe($mensaje);
        $asunto = $this->textoDe($mensaje->subject);

        return new MensajeDeCasilla(
            identificador: MensajeDeCasilla::identificadorPara(
                $this->textoDe($mensaje->message_id),
                $fecha,
                $remitente,
                $asunto,
            ),
            remitente: $remitente,
            asunto: $asunto,
            fecha: $fecha,
            adjuntos: $adjuntos,
            cabeceras: (string) ($mensaje->getHeader()->raw ?? ''),
            uid: (int) $mensaje->getUid(),
        );
    }

    /**
     * ⚠️ Descarta los adjuntos EN LÍNEA, que son el logo de la firma.
     *
     * Media empresa manda mails con firma en HTML, y cada imagen de esa firma
     * viaja como adjunto: sin este filtro, el logotipo de la farmacia entraría
     * como si fuera una receta (un JPG de 4 KB que pasa la lista blanca de
     * `ArchivoService` sin problema). Lo que los distingue es el
     * `Content-Disposition`. Un adjunto sin disposición declarada se acepta: hay
     * clientes que no la mandan, y rechazarlo perdería documentos de verdad.
     */
    private function esArchivoDeVerdad(Attachment $adjunto): bool
    {
        $disposicion = $adjunto->disposition;

        return ! (is_string($disposicion) && mb_strtolower($disposicion) === 'inline');
    }

    private function remitenteDe(Message $mensaje): string
    {
        $de = $mensaje->from->first();

        if (is_object($de) && isset($de->mail) && is_string($de->mail) && $de->mail !== '') {
            return mb_strtolower($de->mail);
        }

        return '(sin remitente)';
    }

    private function textoDe(mixed $atributo): ?string
    {
        if ($atributo === null) {
            return null;
        }

        $valor = is_object($atributo) && method_exists($atributo, 'first')
            ? $atributo->first()
            : $atributo;

        return is_string($valor) ? trim($valor) : null;
    }

    private function carpeta(): string
    {
        return (string) config('misalud.buzon.carpeta');
    }

    /**
     * ⚠️ `ClientManager` NO se resuelve del contenedor: el binding del paquete
     * hace `new ClientManager(config('imap'))` y este proyecto no publica
     * `config/imap.php`, así que eso le pasa `null` a un parámetro que no lo
     * acepta. Se construye con un array vacío -el paquete carga sus defaults- y
     * todo lo que importa va explícito acá.
     */
    private function cliente(): Client
    {
        $direccion = config('misalud.buzon.direccion');
        $password = config('misalud.buzon.password');

        if (! is_string($direccion) || $direccion === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException('Falta configurar el buzón: BUZON_DIRECCION y BUZON_PASSWORD en el .env.');
        }

        return (new ClientManager([]))->make([
            'host' => (string) config('misalud.buzon.host'),
            'port' => (int) config('misalud.buzon.puerto'),
            'protocol' => 'imap',
            // 993 es IMAP sobre TLS. No hay forma de pedirlo sin cifrar: por
            // esa conexión viaja la contraseña.
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => $direccion,
            'password' => $password,
            'authentication' => null,
            'timeout' => self::SEGUNDOS_DE_ESPERA,
            'options' => ['fetch' => IMAP::FT_PEEK],
        ]);
    }
}
