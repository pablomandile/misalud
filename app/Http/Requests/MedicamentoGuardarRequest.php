<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Medicamento;
use App\Rules\IndiceCiegoUnico;
use Illuminate\Foundation\Http\FormRequest;

class MedicamentoGuardarRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nombre_comercial' => [
                'required',
                'string',
                'max:255',
                new IndiceCiegoUnico(
                    Medicamento::class,
                    'nombre_comercial',
                    ['usuario_id' => auth()->id()],
                    $this->medicamentoDeLaRuta()?->id,
                ),
            ],
            'droga' => ['nullable', 'string', 'max:255'],
            'para_que_sirve' => ['nullable', 'string', 'max:500'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre_comercial' => 'nombre comercial',
            'droga' => 'droga',
            'para_que_sirve' => 'para qué sirve',
            'notas' => 'notas',
        ];
    }

    private function medicamentoDeLaRuta(): ?Medicamento
    {
        $medicamento = $this->route('medicamento');

        return $medicamento instanceof Medicamento ? $medicamento : null;
    }
}
