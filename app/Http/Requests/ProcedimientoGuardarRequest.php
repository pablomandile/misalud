<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\AutorizaSobreLaRuta;
use App\Models\Tratamiento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Las etapas del procedimiento de un medicamento, la lista entera y en orden.
 */
class ProcedimientoGuardarRequest extends FormRequest
{
    use AutorizaSobreLaRuta;

    // Autorizar ANTES de validar (ver AutorizaSobreLaRuta).
    public function authorize(): bool
    {
        return $this->puedeGuardar('tratamiento', Tratamiento::class);
    }

    /**
     * Un formulario sin ninguna etapa no manda el campo: es "las saqué todas",
     * no "me olvidé de mandarlas".
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('etapas')) {
            $this->merge(['etapas' => []]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tratamiento = $this->route('tratamiento');
        assert($tratamiento instanceof Tratamiento);

        return [
            // Vacía vale: sacar todas las etapas es dejar el medicamento sin procedimiento.
            'etapas' => ['present', 'array', 'max:30'],
            // Una etapa de OTRO medicamento no se puede renombrar desde acá.
            'etapas.*.id' => ['nullable', 'integer', 'distinct', Rule::in($tratamiento->etapasProcedimiento()->pluck('id')->all())],
            'etapas.*.nombre' => ['required', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'etapas.*.nombre.required' => 'Escribí el nombre de la etapa.',
            'etapas.*.nombre.max' => 'El nombre de la etapa es muy largo.',
            'etapas.*.id.in' => 'Esa etapa no es de este medicamento.',
            'etapas.max' => 'Son demasiadas etapas.',
        ];
    }
}
