<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TipoAdjunto;
use App\Services\ArchivoService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjuntoStoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'archivos' => ['required', 'array', 'min:1', 'max:10'],

            /*
             * `max` va en KILOBYTES, no en bytes: es la unidad de la regla de
             * Laravel y confundirla deja el techo mil veces más alto sin que
             * nada falle hasta que alguien sube algo enorme.
             *
             * `mimetypes` mira el contenido real del archivo, no la extensión
             * ni lo que declaró el navegador. `ArchivoService` vuelve a
             * revisarlo igual: la validación es para dar un mensaje decente, la
             * del servicio es la que protege.
             */
            'archivos.*' => [
                'file',
                'max:'.(int) (ArchivoService::MAXIMO_BYTES / 1024),
                'mimetypes:'.implode(',', ArchivoService::MIMES_ACEPTADOS),
            ],

            'tipo' => ['required', Rule::enum(TipoAdjunto::class)],
            'descripcion' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'archivos' => 'archivos',
            'archivos.*' => 'archivo',
            'tipo' => 'tipo de documento',
            'descripcion' => 'descripción',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivos.required' => 'Elegí al menos un archivo.',
            'archivos.*.mimetypes' => 'Solo se pueden subir PDF o fotos.',
            'archivos.*.max' => 'Cada archivo tiene que pesar menos de :max kilobytes.',
        ];
    }

    public function tipo(): TipoAdjunto
    {
        return TipoAdjunto::from($this->string('tipo')->toString());
    }
}
