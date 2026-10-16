<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            // La casilla de "acepto los términos y la política de privacidad".
            'terminos' => ['accepted'],
        ], [
            'terminos.accepted' => 'Para crear la cuenta tenés que aceptar los términos y la política de privacidad.',
        ])->validate();

        $usuario = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // La constancia de qué versión aceptó y cuándo.
        $usuario->aceptarTerminos();

        return $usuario;
    }
}
