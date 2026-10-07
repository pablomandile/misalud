<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\AutorizaSobreLaRuta;
use App\Models\Medicamento;
use App\Rules\IndiceCiegoUnico;
use Illuminate\Foundation\Http\FormRequest;

class MedicamentoGuardarRequest extends FormRequest
{
    use AutorizaSobreLaRuta;

    /**
     * Autorizar ANTES de validar: sin esto, a un extraño le contestaba la
     * validación y le confirmaba que el registro existe. Ver
     * `AutorizaSobreLaRuta` y `BarridoDePrivacidadTest`.
     */
    public function authorize(): bool
    {
        return $this->puedeGuardar('medicamento', Medicamento::class);
    }

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
