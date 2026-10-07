<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Consulta;
use App\Services\ArchivoService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Subir la grabación de una consulta.
 *
 * Aparte de `AdjuntoStoreRequest` porque el audio cambia todo lo que esa
 * valida: otro techo (64 MB y no 12), otros formatos, y la extensión importa
 * —de ella sale el tipo con el que se sirve—.
 */
class AudioStoreRequest extends FormRequest
{
    /** Seis horas: más es un tipeo o un archivo que no es una consulta. */
    private const DURACION_MAXIMA = 6 * 60 * 60;

    /**
     * Autorizar ANTES de validar. Subir a una consulta es editarla, igual que
     * con cualquier otro dueño de archivos.
     */
    public function authorize(): bool
    {
        $consulta = $this->route('consulta');

        return $consulta instanceof Consulta && Gate::allows('update', $consulta);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'audio' => [
                'required',
                'file',
                // En KILOBYTES: es la unidad de la regla de Laravel.
                'max:'.(int) (ArchivoService::MAXIMO_AUDIO_BYTES / 1024),
                /*
                 * Extensión Y contenido, las dos (ver `ArchivoService::AUDIO_ACEPTADO`).
                 * La regla `mimes` no alcanza: mira solo el contenido, y un m4a
                 * que finfo lee como `video/mp4` pasaría por cualquier `.mp4`.
                 */
                function (string $atributo, mixed $valor, Closure $fallar): void {
                    if ($valor instanceof UploadedFile
                        && app(ArchivoService::class)->extensionDeAudio($valor) === null) {
                        $fallar('Tiene que ser una grabación en m4a, mp3, aac o wav.');
                    }
                },
            ],

            /*
             * La mide el navegador antes de subir: en un hosting compartido no
             * hay `ffprobe`, y es un dato solo para mostrar al lado del nombre.
             * Si no llega, la grabación se guarda igual, sin duración.
             */
            'duracion_segundos' => ['nullable', 'integer', 'min:1', 'max:'.self::DURACION_MAXIMA],

            'descripcion' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function audio(): UploadedFile
    {
        $audio = $this->file('audio');

        // `rules()` ya exige un archivo: esto no puede pasar, y si pasa es un bug.
        if (! $audio instanceof UploadedFile) {
            throw new \LogicException('Falta el audio validado.');
        }

        return $audio;
    }

    public function duracion(): ?int
    {
        $duracion = $this->validated('duracion_segundos');

        return $duracion === null ? null : (int) $duracion;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'audio' => 'grabación',
            'duracion_segundos' => 'duración',
            'descripcion' => 'descripción',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'audio.required' => 'Elegí la grabación.',
            'audio.max' => 'La grabación tiene que pesar menos de 64 MB.',
        ];
    }
}
