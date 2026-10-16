<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AceptarTerminosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'terminos' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'terminos.accepted' => 'Para seguir tenés que aceptar los términos y la política de privacidad.',
        ];
    }
}
