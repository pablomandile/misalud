<?php

declare(strict_types=1);

namespace App\Services;

use GdImage;
use RuntimeException;

/**
 * Gira una foto de a 90°: la caja de un medicamento sacada de costado, una
 * credencial cabeza abajo.
 *
 * Es lo único que se le puede hacer a una imagen desde la app, a propósito: no
 * hay recorte ni filtros. Girar no cambia lo que dice el papel, solo cómo se lo
 * mira.
 *
 * Trabaja sobre los bytes ya descifrados y devuelve bytes: no sabe nada del
 * disco ni del cifrado, que siguen siendo cosa de `ArchivoService`.
 */
class GiradorDeImagenes
{
    /**
     * Los formatos que GD sabe leer y volver a escribir. HEIC queda afuera:
     * GD no lo decodifica, y el botón no se ofrece para esas fotos.
     */
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function admite(string $mime): bool
    {
        return in_array($mime, self::MIMES, true) && function_exists('imagerotate');
    }

    /**
     * @param  int  $grados  90 (a la derecha) o -90 (a la izquierda)
     *
     * @throws RuntimeException si el formato no se admite o la imagen no se lee
     */
    public function girar(string $contenido, string $mime, int $grados): string
    {
        if (! $this->admite($mime)) {
            throw new RuntimeException('Ese tipo de archivo no se puede girar.');
        }

        $imagen = @imagecreatefromstring($contenido);

        if ($imagen === false) {
            throw new RuntimeException('No se pudo leer la imagen.');
        }

        /*
         * ⚠️ Primero se aplica la orientación EXIF. El celular casi nunca gira
         * los píxeles: los guarda como salieron del sensor y anota en el EXIF
         * cómo mostrarlos, y el navegador lo respeta. GD ignora ese dato y al
         * escribir lo descarta, así que sin este paso la foto "saltaría" a otra
         * orientación además de la que se pidió.
         */
        if ($mime === 'image/jpeg') {
            $imagen = $this->enderezarSegunExif($imagen, $contenido);
        }

        // imagerotate gira en sentido ANTIHORARIO: a la derecha es -90.
        $imagen = $this->rotar($imagen, -$grados);

        return $this->escribir($imagen, $mime);
    }

    private function enderezarSegunExif(GdImage $imagen, string $contenido): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $imagen;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($contenido));
        $orientacion = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        // Los valores espejados (2, 4, 5, 7) no los produce una cámara de celular.
        return match ($orientacion) {
            3 => $this->rotar($imagen, 180),
            6 => $this->rotar($imagen, -90),
            8 => $this->rotar($imagen, 90),
            default => $imagen,
        };
    }

    private function rotar(GdImage $imagen, int $grados): GdImage
    {
        if ($grados % 360 === 0) {
            return $imagen;
        }

        // Transparente de fondo: con múltiplos de 90° no queda fondo a la vista,
        // pero un PNG con transparencia la conserva.
        $girada = imagerotate($imagen, $grados, (int) imagecolorallocatealpha($imagen, 0, 0, 0, 127));

        if ($girada === false) {
            throw new RuntimeException('No se pudo girar la imagen.');
        }

        return $girada;
    }

    private function escribir(GdImage $imagen, string $mime): string
    {
        ob_start();

        $escrita = match ($mime) {
            // 92: volver a comprimir pierde algo, y la foto es para leer letra chica.
            'image/jpeg' => imagejpeg($imagen, null, 92),
            'image/png' => $this->png($imagen),
            'image/webp' => imagewebp($imagen, null, 92),
            default => false,
        };

        $bytes = (string) ob_get_clean();

        if (! $escrita || $bytes === '') {
            throw new RuntimeException('No se pudo guardar la imagen girada.');
        }

        return $bytes;
    }

    private function png(GdImage $imagen): bool
    {
        imagealphablending($imagen, false);
        imagesavealpha($imagen, true);

        return imagepng($imagen);
    }
}
