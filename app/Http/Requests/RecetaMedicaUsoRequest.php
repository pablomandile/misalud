<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\RecetaMedica;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Marcar una receta como usada, o deshacerlo. Es su propia acción y no un
 * campo de la edición: es lo que se toca en el mostrador, y obligaría a mandar
 * la receta entera cada vez. Mismo criterio que la bandeja de recetas.
 */
class RecetaMedicaUsoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $receta = $this->route('recetaMedica');

        return $receta instanceof RecetaMedica && Gate::allows('update', $receta);
    }

    /**
     * Un checkbox real manda "on": se normaliza ANTES de que la regla
     * `boolean` lo vea (ver CLAUDE.md, Backend).
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['usada' => $this->boolean('usada')]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'usada' => ['required', 'boolean'],
        ];
    }
}
