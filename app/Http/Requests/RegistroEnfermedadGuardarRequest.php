<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Enfermedad;
use App\Models\User;
use App\Rules\FechaNoFutura;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RegistroEnfermedadGuardarRequest extends FormRequest
{
    /**
     * Anotar en la bitácora es **editar la enfermedad**, no crear algo
     * suyo: se pide `update` sobre ella. Es la misma regla que ya usan los
     * adjuntos con su dueño.
     */
    public function authorize(): bool
    {
        $enfermedad = $this->route('enfermedad');

        return $enfermedad instanceof Enfermedad && Gate::allows('update', $enfermedad);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $usuario = $this->user();

        return [
            'fecha' => array_filter([
                'required',
                'date',
                $usuario instanceof User ? new FechaNoFutura($usuario) : null,
            ]),
            'nota' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'fecha' => 'fecha',
            'nota' => 'nota',
        ];
    }
}
