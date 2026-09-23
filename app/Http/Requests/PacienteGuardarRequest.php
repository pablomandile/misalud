<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PacienteGuardarRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'fecha_nacimiento' => ['nullable', 'date', 'before_or_equal:today'],
            'sexo' => ['nullable', 'string', 'in:femenino,masculino,otro'],
            'grupo_sanguineo' => ['nullable', 'string', 'max:10'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => 'nombre',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'sexo' => 'sexo',
            'grupo_sanguineo' => 'grupo sanguíneo',
            'notas' => 'notas',
        ];
    }
}
