<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\TieneArchivos;
use App\Enums\TipoAdjunto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Cuelga archivos subidos de su dueño: los cifra al disco y crea cada fila
 * de `adjuntos` por la relación.
 *
 * Era el cuerpo de `AdjuntoController::guardarEn()`, y salió de ahí cuando el
 * alta de una orden, de un estudio y de una receta pasó a aceptar el PDF en el
 * mismo formulario: tres controladores más necesitaban exactamente esto, y un
 * cuarto copiar-pegar es donde alguien se olvida de crear la fila por la
 * relación.
 */
final readonly class AdjuntadorDeArchivos
{
    public function __construct(private ArchivoService $archivos) {}

    /**
     * Las reglas de los archivos de un alta, donde son OPCIONALES: se puede
     * cargar la orden hoy y subir el papel mañana. Las mismas que la subida
     * suelta (`AdjuntoStoreRequest`), para que un archivo no entre por un
     * camino y se rechace por el otro.
     *
     * @return array<string, array<int, string>>
     */
    public static function reglasOpcionales(): array
    {
        return [
            'archivos' => ['nullable', 'array', 'max:10'],
            'archivos.*' => [
                'file',
                // `max` va en KILOBYTES (ver AdjuntoStoreRequest).
                'max:'.(int) (ArchivoService::MAXIMO_BYTES / 1024),
                'mimetypes:'.implode(',', ArchivoService::MIMES_ACEPTADOS),
            ],
        ];
    }

    /**
     * ⚠️ Si falla a mitad de camino, borra del disco lo que ya escribió y
     * vuelve a tirar la excepción. Quien lo llama dentro de una transacción
     * pierde también las filas: sin esto quedaría un archivo cifrado que nada
     * referencia, invisible y para siempre.
     *
     * @param  array<int, UploadedFile>  $archivos
     * @return int Cuántos se guardaron.
     */
    public function adjuntar(
        Model&TieneArchivos $duenio,
        array $archivos,
        TipoAdjunto $tipo,
        ?string $descripcion = null,
    ): int {
        $escritas = [];

        try {
            foreach ($archivos as $archivo) {
                $datos = $this->archivos->guardar($archivo, $duenio->carpetaDeArchivos());
                $escritas[] = $datos['ruta'];

                /*
                 * Por la relación y no armando `adjuntable_*` a mano: de esa
                 * referencia cuelga toda la autorización, y por eso no es fillable.
                 */
                $duenio->adjuntos()->create($datos + [
                    'tipo' => $tipo,
                    'descripcion' => $descripcion,
                ]);
            }
        } catch (Throwable $error) {
            foreach ($escritas as $ruta) {
                $this->archivos->borrarRuta($ruta);
            }

            throw $error;
        }

        return count($escritas);
    }
}
