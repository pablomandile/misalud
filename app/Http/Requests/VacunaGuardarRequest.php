<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\AutorizaSobreLaRuta;
use App\Models\Vacuna;
use App\Rules\IndiceCiegoUnico;
use Illuminate\Foundation\Http\FormRequest;

class VacunaGuardarRequest extends FormRequest
{
    use AutorizaSobreLaRuta;

    /**
     * Autorizar ANTES de validar: sin esto, a un extraño le contestaba la
     * validación y le confirmaba que el registro existe. Ver
     * `AutorizaSobreLaRuta` y `BarridoDePrivacidadTest`.
     */
    public function authorize(): bool
    {
        return $this->puedeGuardar('vacuna', Vacuna::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nombre' => [
                'required',
                'string',
                'max:255',
                new IndiceCiegoUnico(
                    Vacuna::class,
                    'nombre',
                    ['usuario_id' => auth()->id()],
                    $this->vacunaDeLaRuta()?->id,
                ),
            ],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => 'nombre',
            'notas' => 'notas',
        ];
    }

    private function vacunaDeLaRuta(): ?Vacuna
    {
        $vacuna = $this->route('vacuna');

        return $vacuna instanceof Vacuna ? $vacuna : null;
    }
}
