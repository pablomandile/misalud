<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Receta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Lo único que se edita de una receta: cuántos días vale.
 *
 * El remitente, el asunto y la fecha de llegada **no se editan**: son lo que
 * dice el mail, y corregirlos sería reescribir de dónde salió el documento. La
 * vigencia sí, porque la que trae el sistema (30 días) es un default: hay
 * recetas de crónicos que valen tres meses y la persona es la única que sabe
 * cuál tiene en la mano.
 */
class RecetaGuardarRequest extends FormRequest
{
    public function authorize(): bool
    {
        $receta = $this->route('receta');

        return $receta instanceof Receta
            && Gate::allows('update', $receta);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /*
             * Se rechaza lo que no puede existir, no lo poco común -el mismo
             * criterio que la validación ocular-: una receta que vale cero días
             * no existe, y más de un año es un tipeo ("300" por "30"). Lo que
             * queda en el medio es decisión de quien tiene el papel.
             */
            'vigencia_dias' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['vigencia_dias' => 'vigencia'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vigencia_dias.integer' => 'La vigencia va en días enteros, como 30.',
            'vigencia_dias.min' => 'La vigencia tiene que ser de al menos un día.',
            'vigencia_dias.max' => 'La vigencia no puede pasar de 365 días.',
        ];
    }
}
