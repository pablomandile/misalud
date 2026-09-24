<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\NormalizaDecimales;
use App\Models\Estudio;
use App\Models\ResultadoEstudio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ResultadoEstudioGuardarRequest extends FormRequest
{
    use NormalizaDecimales;

    /**
     * Cargar o editar un parámetro es **editar el estudio**, no crear algo
     * propio: se pide `update` sobre él. Misma regla que ya usan los
     * adjuntos y la bitácora de una enfermedad con su dueño.
     */
    public function authorize(): bool
    {
        $resultado = $this->resultadoDeLaRuta();

        if ($resultado !== null) {
            return Gate::allows('update', $resultado);
        }

        $estudio = $this->route('estudio');

        return $estudio instanceof Estudio && Gate::allows('update', $estudio);
    }

    protected function prepareForValidation(): void
    {
        // "90,5" es lo que escribe un teclado numérico en español. El valor
        // puede no ser numérico ("Positivo"), así que se normaliza igual
        // -no hace daño- y la conversión real queda para quien lo lea.
        $this->normalizarDecimales(['valor']);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'parametro' => ['required', 'string', 'max:255'],
            'valor' => ['required', 'string', 'max:255'],
            'unidad' => ['nullable', 'string', 'max:50'],
            'rango_referencia' => ['nullable', 'string', 'max:100'],
        ];
    }

    private function resultadoDeLaRuta(): ?ResultadoEstudio
    {
        $resultado = $this->route('resultado');

        return $resultado instanceof ResultadoEstudio ? $resultado : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'parametro' => 'parámetro',
            'valor' => 'valor',
            'unidad' => 'unidad',
            'rango_referencia' => 'rango de referencia',
        ];
    }
}
