<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\TieneArchivos;
use App\Enums\TipoAdjunto;
use App\Support\AdjuntoDeCasilla;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RuntimeException;
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
        ?string $titulo = null,
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
                    'titulo' => $this->tituloPara($titulo, count($escritas), count($archivos)),
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

    /**
     * Con varios archivos y un solo nombre ("Análisis de marzo"), cada uno
     * lleva su número: tres documentos que se llaman igual no se distinguen en
     * la lista.
     */
    private function tituloPara(?string $titulo, int $posicion, int $total): ?string
    {
        $titulo = $titulo === null ? '' : trim($titulo);

        if ($titulo === '') {
            return null;
        }

        return $total > 1 ? "{$titulo} ({$posicion} de {$total})" : $titulo;
    }

    /**
     * Lo mismo, para archivos que no llegaron por un formulario sino por mail.
     * El mime se deduce del CONTENIDO (`ArchivoService::guardarContenido()`),
     * nunca del `Content-Type` del mail, que lo escribe alguien de afuera.
     *
     * Mismo cuidado que `adjuntar()`: si algo falla, borra lo ya escrito.
     *
     * @param  list<AdjuntoDeCasilla>  $archivos  ya filtrados (ver `utiles()`)
     */
    public function adjuntarContenidos(
        Model&TieneArchivos $duenio,
        array $archivos,
        TipoAdjunto $tipo,
        ?string $descripcion = null,
    ): int {
        $escritas = [];

        try {
            foreach ($archivos as $archivo) {
                $datos = $this->archivos->guardarContenido($archivo->contenido, $archivo->nombre, $duenio->carpetaDeArchivos());
                $escritas[] = $datos['ruta'];

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

    /**
     * De lo que llegó por mail, lo que sirve: hasta 10, de un tipo y un tamaño
     * que se aceptan. Se descartan DE A UNO, no el mail entero: si trae la
     * receta en PDF y además un instructivo en Word, la receta entra. Pregunta
     * sin escribir nada, así se decide antes de abrir una transacción.
     *
     * @param  list<AdjuntoDeCasilla>  $archivos
     * @return list<AdjuntoDeCasilla>
     */
    public function utiles(array $archivos): array
    {
        $utiles = array_values(array_filter($archivos, function (AdjuntoDeCasilla $archivo): bool {
            try {
                $this->archivos->mimeAceptado($archivo->contenido);

                return true;
            } catch (RuntimeException) {
                return false;
            }
        }));

        return array_slice($utiles, 0, 10);
    }

    /**
     * Pasa los archivos de un dueño a otro: lo que quedó "por revisar" se
     * convierte en una receta, una orden o un estudio, y sus archivos se van
     * con él. **No se copia nada en el disco**: cambia de quién cuelga la fila,
     * y la ruta cifrada sigue apuntando al mismo archivo.
     *
     * `adjuntable` no es fillable (de él cuelga la autorización), así que va
     * por `associate()`, el único camino.
     */
    public function traspasar(TieneArchivos $de, Model&TieneArchivos $a, TipoAdjunto $tipo): void
    {
        foreach ($de->adjuntos()->get() as $adjunto) {
            $adjunto->adjuntable()->associate($a);
            $adjunto->setAttribute('tipo', $tipo);
            $adjunto->save();
        }
    }
}
