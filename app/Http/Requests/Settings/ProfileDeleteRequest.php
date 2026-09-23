<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /*
         * Sin contraseña no se pide contraseña.
         *
         * Con la regla fija, una cuenta de Google NO PUEDE eliminarse nunca:
         * `current_password` se evalúa contra un hash vacío y falla siempre, y
         * la persona queda sin ninguna forma de borrar sus propios datos. El
         * riesgo que queda -una sesión abierta y sin dueño- ya existe igual:
         * esa misma sesión puede borrar los pacientes uno por uno.
         *
         * Para quien sí tiene contraseña no cambia nada: se le sigue pidiendo.
         */
        if (blank($this->user()?->getAuthPassword())) {
            return [];
        }

        return [
            'password' => $this->currentPasswordRules(),
        ];
    }
}
