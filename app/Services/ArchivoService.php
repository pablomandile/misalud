<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\TieneArchivos;
use App\Models\Adjunto;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Guarda y recupera los archivos de la historia clínica, **cifrados en disco**.
 *
 * El contenido se cifra con la misma `APP_KEY` que el resto: un backup de
 * `storage/` sin la clave no sirve para nada, que es justamente el punto.
 *
 * Lo que se paga por eso, y hay que tenerlo presente antes de apoyar algo
 * encima:
 *
 * - **No hay _range requests_.** El archivo se sirve entero, siempre. Para un
 *   PDF de consultorio es irrelevante; para audio o video significa que no se
 *   puede adelantar, y que iOS Safari -que exige `Range` para `<audio>`- puede
 *   directamente no reproducir. Si algún día entra audio, eso se resuelve ahí,
 *   no acá.
 * - **Se desencripta entero en memoria.** Entre leer, descifrar y responder se
 *   usan varias veces el tamaño del archivo, así que el límite de subida no es
 *   una formalidad: es lo que evita que un PDF grande tumbe el proceso en un
 *   hosting compartido.
 */
class ArchivoService
{
    /**
     * Techo de subida.
     *
     * Bajo a propósito. Entre el original, el cifrado y la respuesta, servir un
     * archivo cuesta varias veces su tamaño en memoria, y el `memory_limit` de
     * un hosting compartido no es generoso. Un informe escaneado no llega ni
     * cerca; una tomografía completa no es el caso de uso de esta app.
     */
    public const MAXIMO_BYTES = 12 * 1024 * 1024;

    /**
     * Lo que se acepta subir.
     *
     * **Lista blanca, no lista negra.** Todo lo que no esté acá se rechaza, así
     * que un formato nuevo nace prohibido y hay que habilitarlo a mano. Al
     * revés —prohibir lo peligroso— siempre falta algo.
     *
     * No hay `text/html` ni `image/svg+xml` a propósito: los dos ejecutan
     * JavaScript si el navegador los abre, y estos archivos se sirven desde el
     * propio dominio de la app.
     *
     * @var list<string>
     */
    public const MIMES_ACEPTADOS = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
    ];

    /**
     * Guarda el archivo cifrado y devuelve los datos para la fila de `adjuntos`.
     *
     * @return array{ruta: string, nombre_original: string, mime: string, tamanio_bytes: int}
     */
    public function guardar(UploadedFile $archivo, string $carpeta): array
    {
        $bytes = $archivo->getSize();

        if ($bytes === false || $bytes > self::MAXIMO_BYTES) {
            throw new RuntimeException('El archivo supera el tamaño máximo permitido.');
        }

        /*
         * `getMimeType()` y NO `getClientMimeType()`: el segundo lo manda el
         * navegador y lo elige quien sube. El primero lo deduce del contenido
         * real con finfo. Confiar en el del cliente permitiría subir cualquier
         * cosa diciendo que es un PDF.
         */
        $mime = $archivo->getMimeType() ?? 'application/octet-stream';

        if (! in_array($mime, self::MIMES_ACEPTADOS, true)) {
            throw new RuntimeException("Tipo de archivo no permitido: {$mime}.");
        }

        $contenido = file_get_contents($archivo->getRealPath());

        if ($contenido === false) {
            throw new RuntimeException('No se pudo leer el archivo subido.');
        }

        return $this->escribir($contenido, $archivo->getClientOriginalName(), $mime, $carpeta);
    }

    /**
     * Lo mismo, pero a partir del contenido en memoria.
     *
     * Existe para los archivos que **no vienen de un formulario**: los adjuntos
     * de un mail importado (paso 12.2), que llegan como bytes y nunca fueron un
     * `UploadedFile`.
     *
     * @return array{ruta: string, nombre_original: string, mime: string, tamanio_bytes: int}
     */
    public function guardarContenido(string $contenido, string $nombreOriginal, string $carpeta): array
    {
        return $this->escribir(
            $contenido,
            $nombreOriginal,
            $this->mimeAceptado($contenido),
            $carpeta,
        );
    }

    /**
     * El mime real del contenido, si es de los que se aceptan.
     *
     * ⚠️ **Se deduce del contenido con finfo, nunca de lo que diga quien lo
     * manda.** Es la misma regla que hace que una subida use `getMimeType()` y no
     * `getClientMimeType()`, y en un mail importa todavía más: el
     * `Content-Type` de un adjunto lo escribe el remitente, que es alguien de
     * afuera.
     *
     * Separado de `guardarContenido()` a propósito: deja preguntar "¿esto sirve?"
     * **sin escribir nada**, que es lo que necesita la importación de recetas para
     * descartar el logo de una firma antes de abrir una transacción.
     *
     * @throws RuntimeException si no entra por tamaño o por tipo
     */
    public function mimeAceptado(string $contenido): string
    {
        if ($contenido === '') {
            throw new RuntimeException('El archivo está vacío.');
        }

        if (strlen($contenido) > self::MAXIMO_BYTES) {
            throw new RuntimeException('El archivo supera el tamaño máximo permitido.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new RuntimeException('No se pudo inspeccionar el tipo del archivo.');
        }

        $mime = finfo_buffer($finfo, $contenido);
        finfo_close($finfo);

        if ($mime === false || ! in_array($mime, self::MIMES_ACEPTADOS, true)) {
            throw new RuntimeException('Tipo de archivo no permitido: '.($mime ?: 'desconocido').'.');
        }

        return $mime;
    }

    /**
     * Borra un archivo del disco por su ruta, sin pasar por su fila.
     *
     * Lo necesita la importación de recetas para limpiar lo que ya había escrito
     * cuando la transacción que iba a crear las filas se cae: sin esto quedaría un
     * archivo cifrado que nada referencia, invisible y para siempre.
     */
    public function borrarRuta(string $ruta): bool
    {
        return $this->disco()->delete($ruta);
    }

    /**
     * El tramo común: cifra, escribe y arma los datos de la fila.
     *
     * @return array{ruta: string, nombre_original: string, mime: string, tamanio_bytes: int}
     */
    private function escribir(string $contenido, string $nombreOriginal, string $mime, string $carpeta): array
    {
        /*
         * Nombre aleatorio y extensión `.cif`. Aleatorio porque el nombre
         * original es contenido clínico y no tiene por qué quedar escrito en el
         * disco en claro; `.cif` porque el archivo NO es un PDF -está cifrado- y
         * ponerle `.pdf` hace que quien lo encuentre en un backup pierda un rato
         * averiguando por qué no abre.
         */
        $ruta = trim($carpeta, '/').'/'.Str::ulid()->toString().'.cif';

        $this->disco()->put($ruta, Crypt::encryptString($contenido));

        return [
            'ruta' => $ruta,
            'nombre_original' => $this->nombreLimpio($nombreOriginal),
            'mime' => $mime,
            // El tamaño del ORIGINAL, no el del cifrado: es el número que la
            // persona reconoce como "su" archivo.
            'tamanio_bytes' => strlen($contenido),
        ];
    }

    /**
     * El contenido descifrado, listo para mandar al navegador.
     */
    public function contenido(Adjunto $adjunto): string
    {
        $disco = $this->disco();

        if (! $disco->exists($adjunto->ruta)) {
            throw new RuntimeException("El archivo [{$adjunto->ruta}] no está en el disco.");
        }

        $crudo = $disco->get($adjunto->ruta);

        if ($crudo === null) {
            throw new RuntimeException("No se pudo leer el archivo [{$adjunto->ruta}].");
        }

        return Crypt::decryptString($crudo);
    }

    /**
     * Borra el archivo del disco.
     *
     * Devuelve `false` si no estaba, sin explotar: un adjunto cuyo archivo ya no
     * existe igual tiene que poder eliminarse de la base, o queda una fila que
     * no se puede borrar nunca.
     */
    public function borrar(Adjunto $adjunto): bool
    {
        return $this->disco()->delete($adjunto->ruta);
    }

    /**
     * Borra DE VERDAD todos los archivos de un dueño que se va a borrar de
     * verdad: primero el disco, después las filas (la regla de los adjuntos; al
     * revés, un archivo cifrado quedaría sin nada que lo referencie, invisible y
     * para siempre).
     *
     * Con la papelera de los adjuntos incluida: un archivo que se borró antes ya
     * no está en el disco, pero su fila sigue ahí apuntando a este dueño.
     *
     * Lo usan los catálogos y las coberturas, los dos dueños que se borran sin
     * papelera.
     */
    public function borrarTodosDe(TieneArchivos $duenio): void
    {
        foreach ($duenio->adjuntos()->withTrashed()->get() as $adjunto) {
            $this->borrar($adjunto);
            $adjunto->forceDelete();
        }
    }

    /**
     * El nombre original, recortado y sin nada que sirva para salir de la
     * carpeta.
     *
     * No se usa para construir la ruta -esa es aleatoria- pero sí viaja al
     * `Content-Disposition` al descargar, así que conviene que no traiga
     * barras ni saltos de línea.
     */
    private function nombreLimpio(string $nombre): string
    {
        $nombre = basename(str_replace('\\', '/', $nombre));
        $nombre = preg_replace('/[\x00-\x1F\x7F"]/u', '', $nombre) ?? $nombre;

        return Str::limit(trim($nombre), 180, '') ?: 'documento';
    }

    /**
     * El disco privado. Nunca el público: estos archivos se sirven por
     * controlador, después de verificar quién pregunta.
     */
    private function disco(): Filesystem
    {
        return Storage::disk('local');
    }
}
