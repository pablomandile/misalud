<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\NormalizaDecimales;
use App\Models\TipoMedicion;
use App\Rules\IndiceCiegoUnico;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TipoMedicionGuardarRequest extends FormRequest
{
    use NormalizaDecimales;

    protected function prepareForValidation(): void
    {
        // Los rangos de referencia también los escribe una persona: misma
        // coma decimal y misma trampa que en el valor de una medición.
        $this->normalizarDecimales([
            'min_normal',
            'max_normal',
            'min_normal_secundario',
            'max_normal_secundario',
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // Un rango secundario sin etiqueta secundaria describiría un valor
        // que este tipo no pide nunca: queda guardado, no lo lee nadie, y
        // reaparece como una banda equivocada en el gráfico.
        $sinSegundoValor = blank($this->input('etiqueta_secundaria'));

        return [
            'nombre' => [
                'required',
                'string',
                'max:255',
                new IndiceCiegoUnico(
                    TipoMedicion::class,
                    'nombre',
                    ['usuario_id' => auth()->id()],
                    $this->tipoDeLaRuta()?->id,
                ),
            ],
            'unidad' => ['required', 'string', 'max:20'],
            'etiqueta_principal' => ['nullable', 'string', 'max:60'],
            'etiqueta_secundaria' => ['nullable', 'string', 'max:60'],
            'unidad_secundaria' => [
                Rule::prohibitedIf($sinSegundoValor),
                'nullable',
                'string',
                'max:20',
            ],

            'min_normal' => ['nullable', 'numeric'],
            'max_normal' => ['nullable', 'numeric', 'gte:min_normal'],
            'min_normal_secundario' => [
                Rule::prohibitedIf($sinSegundoValor),
                'nullable',
                'numeric',
            ],
            'max_normal_secundario' => [
                Rule::prohibitedIf($sinSegundoValor),
                'nullable',
                'numeric',
                'gte:min_normal_secundario',
            ],

            'decimales' => ['required', 'integer', 'between:0,3'],
        ];
    }

    private function tipoDeLaRuta(): ?TipoMedicion
    {
        $tipo = $this->route('tipo_medicion');

        return $tipo instanceof TipoMedicion ? $tipo : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => 'nombre',
            'unidad' => 'unidad',
            'etiqueta_principal' => 'nombre del primer valor',
            'etiqueta_secundaria' => 'nombre del segundo valor',
            'unidad_secundaria' => 'unidad del segundo valor',
            'min_normal' => 'mínimo de referencia',
            'max_normal' => 'máximo de referencia',
            'min_normal_secundario' => 'mínimo de referencia del segundo valor',
            'max_normal_secundario' => 'máximo de referencia del segundo valor',
            'decimales' => 'decimales',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unidad_secundaria.prohibited' => 'Poné primero el nombre del segundo valor.',
            'min_normal_secundario.prohibited' => 'Poné primero el nombre del segundo valor.',
            'max_normal_secundario.prohibited' => 'Poné primero el nombre del segundo valor.',
            'max_normal.gte' => 'El máximo de referencia no puede ser menor que el mínimo.',
            'max_normal_secundario.gte' => 'El máximo de referencia no puede ser menor que el mínimo.',
        ];
    }
}
