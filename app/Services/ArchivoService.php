<?php

declare(strict_types=1);

namespace App\Services;

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
            'nombre_original' => $this->nombreLimpio($archivo->getClientOriginalName()),
            'mime' => $mime,
            // El tamaño del ORIGINAL, no el del cifrado: es el número que la
            // persona reconoce como "su" archivo.
            'tamanio_bytes' => $bytes,
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
