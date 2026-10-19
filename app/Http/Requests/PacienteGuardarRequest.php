<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\AutorizaSobreLaRuta;
use App\Models\Paciente;
use Illuminate\Foundation\Http\FormRequest;

class PacienteGuardarRequest extends FormRequest
{
    use AutorizaSobreLaRuta;

    /**
     * Autorizar ANTES de validar: sin esto, a un extraño le contestaba la
     * validación y le confirmaba que el registro existe. Ver
     * `AutorizaSobreLaRuta` y `BarridoDePrivacidadTest`.
     */
    public function authorize(): bool
    {
        return $this->puedeGuardar('paciente', Paciente::class);
    }

    /**
     * El DNI se escribe como figura en el documento ("12.345.678") y se guarda
     * solo con los dígitos: los puntos son formato, no dato. Se limpia ANTES de
     * validar, igual que la coma decimal, o la regla rechazaría lo que la
     * persona escribió bien. El correo, sin espacios y en minúsculas.
     */
    protected function prepareForValidation(): void
    {
        $dni = $this->input('dni');
        $email = $this->input('email');

        $this->merge([
            'dni' => is_string($dni) ? (preg_replace('/[\s.\-]/', '', $dni) ?: null) : $dni,
            'email' => is_string($email) ? (mb_strtolower(trim($email)) ?: null) : $email,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            // Siete u ocho dígitos en la práctica; se acepta de 6 a 9 para no
            // dejar afuera un DNI viejo. Se rechaza lo que no puede ser un DNI.
            'dni' => ['nullable', 'string', 'regex:/^\d{6,9}$/'],
            'fecha_nacimiento' => ['nullable', 'date', 'before_or_equal:today'],
            'sexo' => ['nullable', 'string', 'in:femenino,masculino,otro'],
            'grupo_sanguineo' => ['nullable', 'string', 'max:10'],
            'telefono' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\s.\-]+$/'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dni.regex' => 'El DNI tiene que ser solo números, por ejemplo 12.345.678.',
            'telefono.regex' => 'El teléfono solo puede tener números, espacios, guiones, paréntesis y el +.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => 'nombre',
            'dni' => 'DNI',
            'telefono' => 'teléfono',
            'email' => 'correo electrónico',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'sexo' => 'sexo',
            'grupo_sanguineo' => 'grupo sanguíneo',
            'notas' => 'notas',
        ];
    }
}
