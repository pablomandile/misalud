<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PasswordUpdateRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $reglas = ['password' => $this->passwordRules()];

        /*
         * A quien entró con Google no se le pide la contraseña actual: no tiene
         * ninguna, y exigirla lo dejaría sin poder definirse una nunca -que es
         * justo lo que hay que hacer para dejar de depender de Google-.
         */
        if (filled($this->user()?->getAuthPassword())) {
            $reglas['current_password'] = $this->currentPasswordRules();
        }

        return $reglas;
    }
}
