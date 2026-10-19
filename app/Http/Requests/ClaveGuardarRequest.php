<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Clave;
use App\Models\Paciente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ClaveGuardarRequest extends FormRequest
{
    /**
     * Autorizar ANTES de validar (ver `AutorizaSobreLaRuta`). Al editar, pide
     * `update` sobre la clave; al crear, poder cargar datos en la ficha.
     */
    public function authorize(): bool
    {
        $clave = $this->route('clave');

        if ($clave instanceof Clave) {
            return Gate::allows('update', $clave);
        }

        $paciente = $this->route('paciente');

        return $paciente instanceof Paciente
            && Gate::allows('crearEn', [Clave::class, $paciente]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'sitio' => ['required', 'string', 'max:255'],
            'usuario' => ['nullable', 'string', 'max:255'],
            /*
             * La clave es de SOLO ESCRITURA en el formulario, igual que la de la
             * casilla: al editar el campo llega vacío y significa "dejá la que
             * está". Obligarla siempre haría retipearla para corregir una nota.
             */
            'clave' => [$this->route('clave') instanceof Clave ? 'nullable' : 'required', 'string', 'max:500'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sitio' => 'sitio',
            'usuario' => 'usuario',
            'clave' => 'clave',
            'notas' => 'notas',
        ];
    }
}
