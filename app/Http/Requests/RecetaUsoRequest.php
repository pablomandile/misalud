<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Receta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Marcar una receta como usada, o deshacerlo.
 *
 * Es su propia acción y no un campo más de la edición: lo que se toca muchas
 * veces es esto -en la farmacia, con el celular en la mano- y la vigencia casi
 * nunca. Mezclarlos obligaría a mandar la vigencia cada vez que se marca una
 * receta, y un formulario que manda de más es uno que algún día pisa algo.
 */
class RecetaUsoRequest extends FormRequest
{
    /**
     * Autorizar antes de validar: sin esto, a alguien sin permiso le contesta
     * primero la validación y de paso le confirma que la receta existe.
     */
    public function authorize(): bool
    {
        $receta = $this->route('receta');

        return $receta instanceof Receta
            && Gate::allows('update', $receta);
    }

    protected function prepareForValidation(): void
    {
        // ⚠️ Un checkbox tildado manda el string "on", que la regla `boolean`
        // rechaza. Ver la regla en CLAUDE.md, sección Backend.
        $this->merge(['usada' => $this->boolean('usada')]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['usada' => ['required', 'boolean']];
    }
}
