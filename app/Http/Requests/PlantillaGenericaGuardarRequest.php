<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El mensaje genérico del pedido de medicamentos. Es de la cuenta de quien lo
 * escribe, no de una ficha: no hay registro que autorizar más allá de la sesión.
 */
class PlantillaGenericaGuardarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // El mismo tope que el mensaje del pedido: lo que se guarda acá se manda.
        return [
            'mensaje' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mensaje.required' => 'Escribí el mensaje genérico, o tocá «Volver al original».',
            'mensaje.max' => 'El mensaje es muy largo.',
        ];
    }
}
