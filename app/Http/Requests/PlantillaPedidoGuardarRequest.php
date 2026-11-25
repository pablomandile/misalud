<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\AutorizaSobreLaRuta;
use App\Models\Tratamiento;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El mensaje por defecto de un medicamento para el pedido por mail, guardado sin
 * mandar nada. Es el mismo campo que el pedido actualiza al mandar.
 */
class PlantillaPedidoGuardarRequest extends FormRequest
{
    use AutorizaSobreLaRuta;

    // Autorizar ANTES de validar (ver AutorizaSobreLaRuta).
    public function authorize(): bool
    {
        return $this->puedeGuardar('tratamiento', Tratamiento::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // El mismo tope que el mensaje del pedido: lo que se guarda acá se manda tal cual.
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
            'mensaje.required' => 'Escribí el mensaje que querés guardar.',
            'mensaje.max' => 'El mensaje es muy largo.',
        ];
    }
}
