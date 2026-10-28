<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\AutorizaSobreLaRuta;
use App\Concerns\CatalogoDeLaRuta;
use App\Concerns\ValidaDatosDeContacto;
use App\Models\Farmacia;
use App\Rules\IndiceCiegoUnico;
use Illuminate\Foundation\Http\FormRequest;

class FarmaciaGuardarRequest extends FormRequest
{
    use AutorizaSobreLaRuta;
    use CatalogoDeLaRuta;
    use ValidaDatosDeContacto;

    /**
     * Autorizar ANTES de validar (ver `AutorizaSobreLaRuta`).
     */
    public function authorize(): bool
    {
        return $this->puedeGuardar('farmacia', Farmacia::class);
    }

    /**
     * La web sin `https://` y el correo en minúsculas, antes de validar (ver
     * `ValidaDatosDeContacto`).
     */
    protected function prepareForValidation(): void
    {
        $this->normalizarDatosDeContacto();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $farmacia = $this->route('farmacia');

        return [
            'nombre' => [
                'required',
                'string',
                'max:255',
                new IndiceCiegoUnico(
                    Farmacia::class,
                    'nombre',
                    ['usuario_id' => $this->duenioDelCatalogo('farmacia')],
                    $farmacia instanceof Farmacia ? $farmacia->id : null,
                ),
            ],
            ...$this->reglasDeContacto(),
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->mensajesDeContacto();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => 'nombre',
            ...$this->atributosDeContacto(),
            'notas' => 'notas',
        ];
    }
}
