<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\AutorizaSobreLaRuta;
use App\Enums\TipoContacto;
use App\Models\Contacto;
use App\Rules\IndiceCiegoUnico;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactoGuardarRequest extends FormRequest
{
    use AutorizaSobreLaRuta;

    /**
     * Autorizar ANTES de validar: sin esto, a un extraño le contestaba la
     * validación y le confirmaba que el registro existe. Ver
     * `AutorizaSobreLaRuta` y `BarridoDePrivacidadTest`.
     */
    public function authorize(): bool
    {
        return $this->puedeGuardar('contacto', Contacto::class);
    }

    /**
     * La dirección sin espacios y en minúscula, ANTES de validar.
     *
     * El índice ciego ya compara en minúscula, pero guardar la forma normalizada
     * hace que la libreta y el historial muestren la dirección igual que la va a
     * usar el servidor de correo, y no "Recetas@Farmacia.com" en un lado y
     * "recetas@farmacia.com" en otro.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $contacto = $this->route('contacto');

        return [
            'nombre' => ['required', 'string', 'max:150'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                new IndiceCiegoUnico(
                    Contacto::class,
                    'email',
                    ['usuario_id' => $this->user()?->id],
                    $contacto instanceof Contacto ? $contacto->id : null,
                ),
            ],
            'tipo' => ['required', Rule::enum(TipoContacto::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => 'nombre',
            'email' => 'dirección de correo',
            'tipo' => 'tipo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.email' => 'Revisá la dirección: tiene que ser como recetas@farmacia.com.ar.',
        ];
    }
}
