<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CuentaMail;
use App\Support\AdjuntoDeCasilla;
use App\Support\MensajeDeCasilla;
use Carbon\CarbonImmutable;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * Lo único que habla IMAP. Traduce mensajes a `MensajeDeCasilla` y nada más.
 *
 * ## Es tonto a propósito
 *
 * Acá no hay ninguna regla: ni la ventana, ni el filtro por remitente, ni la
 * deduplicación, ni qué adjunto sirve. Todo eso vive en
 * `SincronizadorDeRecetas`, que recibe DTOs y **se puede probar entero sin
 * red**. Esta clase es la costura, y es la única parte del paso que no tiene
 * tests automáticos porque no se puede tener sin un servidor de verdad: por eso
 * conviene que no decida nada.
 *
 * ## ⚠️ No se toca la casilla de la persona
 *
 * La pantalla promete "solo lee: no manda mails ni borra nada", y esto es lo que
 * lo cumple. Dos cosas, las dos explícitas:
 *
 * - **`FT_PEEK`**, que trae el mensaje sin ponerle la marca `\Seen`. Es el
 *   default del paquete, y va escrito igual: si algún día cambia, el síntoma
 *   sería que la app le marca como leídos los mails a alguien, y eso no se
 *   descubre con un test.
 * - **`leaveUnread()`** en la consulta, que es lo mismo por el otro lado.
 *
 * ## ⚠️ El `SINCE` de IMAP compara FECHAS, no instantes
 *
 * Es por día, contra la fecha interna del servidor, y es `>=`. Así que la
 * ventana que se pide siempre es un poco más ancha que la calculada —arranca a
 * la medianoche de ese día— y nunca más angosta. Eso es exactamente lo que se
 * quiere: de más es gratis (el UNIQUE de `message_id_hash` frena la
 * reimportación), de menos sería un agujero silencioso.
 */
class LectorDeCasilla
{
    /** Lo mismo que la prueba de conexión: alguien puede estar esperando. */
    private const SEGUNDOS_DE_ESPERA = 20;

    /**
     * Los mensajes de la casilla desde una fecha, del más viejo al más nuevo.
     *
     * El orden importa y no es cosmético: `SincronizadorDeRecetas` mueve la
     * marca de sincronización a la fecha del último mensaje que procesó, y para
     * que eso sea correcto tiene que procesarlos en orden.
     *
     * @return list<MensajeDeCasilla>
     */
    public function mensajesDesde(CuentaMail $cuenta, CarbonImmutable $desde, int $tope): array
    {
        $cliente = $this->cliente($cuenta);

        try {
            $cliente->connect();

            $carpeta = $cliente->getFolderByPath($cuenta->carpeta);

            if ($carpeta === null) {
                // La prueba de conexión del 12.1 existe justamente para que esto
                // se descubra antes y con un mensaje que explique el separador.
                throw new \RuntimeException("La carpeta [{$cuenta->carpeta}] no existe en la casilla.");
            }

            $mensajes = $carpeta->query()
                ->whereSince($desde->toDateString())
                ->leaveUnread()
                ->setFetchOrder('asc')
                ->limit($tope)
                ->get();

            $traducidos = [];

            foreach ($mensajes as $mensaje) {
                $traducidos[] = $this->traducir($mensaje);
            }

            return $traducidos;
        } finally {
            try {
                $cliente->disconnect();
            } catch (\Throwable) {
                // Cerrar nunca puede cambiar el resultado de la lectura.
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
        );
    }

    /**
     * ⚠️ Descarta los adjuntos EN LÍNEA, que son el logo de la firma.
     *
     * Media empresa manda mails con firma en HTML, y cada imagen de esa firma
     * viaja como adjunto. Sin este filtro, una casilla sin filtros de remitente
     * importa el logotipo de la farmacia como si fuera una receta: un JPG de 4 KB
     * que pasa la lista blanca de `ArchivoService` sin problema.
     *
     * Lo que distingue a uno de otro es el `Content-Disposition`: un archivo que
     * alguien adjuntó va como `attachment`, y una imagen incrustada en el cuerpo
     * va como `inline` y además trae `Content-ID` para que el HTML la referencie.
     *
     * Un adjunto sin disposición declarada se acepta: hay clientes de correo que
     * no la mandan, y rechazarlo perdería recetas de verdad. El caso que hay que
     * atajar es el `inline` explícito.
     */
    private function esArchivoDeVerdad(Attachment $adjunto): bool
    {
        $disposicion = $adjunto->disposition;

        if (is_string($disposicion) && mb_strtolower($disposicion) === 'inline') {
            return false;
        }

        return true;
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

        if (is_string($valor)) {
            return trim($valor);
        }

        return null;
    }

    /**
     * Mismas elecciones que `ProbadorDeCasilla::cliente()`, y por los mismos
     * motivos -ahí está el detalle de cada una-.
     */
    private function cliente(CuentaMail $cuenta): Client
    {
        return (new ClientManager)->make([
            'host' => $cuenta->host,
            'port' => $cuenta->puerto,
            'protocol' => 'imap',
            'encryption' => $cuenta->encriptacion(),
            'validate_cert' => true,
            'username' => $cuenta->direccion,
            'password' => $cuenta->password,
            'authentication' => null,
            'timeout' => self::SEGUNDOS_DE_ESPERA,

            // Traer el mensaje SIN marcarlo como leído. Ver el comentario de la
            // clase: es la promesa de "solo lee" de la pantalla.
            'options' => ['fetch' => IMAP::FT_PEEK],
        ]);
    }
}
