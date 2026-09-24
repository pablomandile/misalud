<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Vacuna;
use App\Rules\IndiceCiegoUnico;
use Illuminate\Foundation\Http\FormRequest;

class VacunaGuardarRequest extends FormRequest
{
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
