<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\AutorizaSobreLaRuta;
use App\Models\Medico;
use App\Rules\IndiceCiegoUnico;
use Illuminate\Foundation\Http\FormRequest;

class MedicoGuardarRequest extends FormRequest
{
    use AutorizaSobreLaRuta;

    /**
     * Autorizar ANTES de validar: sin esto, a un extraño le contestaba la
     * validación y le confirmaba que el registro existe. Ver
     * `AutorizaSobreLaRuta` y `BarridoDePrivacidadTest`.
     */
    public function authorize(): bool
    {
        return $this->puedeGuardar('medico', Medico::class);
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
                /*
                 * Unicidad DENTRO del catálogo de esta persona: dos usuarios
                 * distintos pueden tener cada uno a su "Dr. Pérez". Va por el
                 * índice ciego porque `nombre` está cifrado y `Rule::unique`
                 * no detectaría nada (ver IndiceCiegoUnico).
                 */
                new IndiceCiegoUnico(
                    Medico::class,
                    'nombre',
                    ['usuario_id' => auth()->id()],
                    $this->medicoDeLaRuta()?->id,
                ),
            ],
            'especialidad' => ['nullable', 'string', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
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
            'especialidad' => 'especialidad',
            'telefono' => 'teléfono',
            'email' => 'email',
            'notas' => 'notas',
        ];
    }

    private function medicoDeLaRuta(): ?Medico
    {
        $medico = $this->route('medico');

        return $medico instanceof Medico ? $medico : null;
    }
}
