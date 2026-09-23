<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TipoCobertura;
use App\Models\Cobertura;
use App\Models\Paciente;
use App\Rules\IndiceCiegoUnico;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CoberturaGuardarRequest extends FormRequest
{
    /**
     * Normaliza el checkbox ANTES de validar.
     *
     * Un `<input type="checkbox">` nativo manda el string `"on"` cuando
     * está tildado, y NO manda la clave cuando no lo está -nunca manda
     * `"false"`-. La regla `boolean` de Laravel solo acepta
     * `true/false/1/0/"1"/"0"`: `"on"` la hace fallar, y como nada en la
     * pantalla mostraba el error de este campo en particular, la creación
     * fallaba en silencio -302 de vuelta, sin fila nueva, sin ningún
     * cartel-. Se normaliza acá y no en el controlador: así la regla
     * `boolean` de abajo valida un valor que ya tiene sentido, y
     * `$this->boolean('activa')` en el controlador puede seguir usando su
     * propio default sin pelearse con esto.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('activa')) {
            $this->merge(['activa' => $this->boolean('activa')]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::enum(TipoCobertura::class)],
            'entidad' => [
                'required',
                'string',
                'max:255',
                new IndiceCiegoUnico(
                    Cobertura::class,
                    'entidad',
                    ['paciente_id' => $this->pacienteId()],
                    $this->coberturaDeLaRuta()?->id,
                ),
            ],
            'plan' => ['nullable', 'string', 'max:255'],
            'nro_afiliado' => ['nullable', 'string', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'telefono_urgencias' => ['nullable', 'string', 'max:50'],
            'sitio_web' => ['nullable', 'string', 'max:255'],
            'vigencia_desde' => ['nullable', 'date'],
            'vigencia_hasta' => ['nullable', 'date', 'after_or_equal:vigencia_desde'],
            'activa' => ['nullable', 'boolean'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * El paciente dueño de la cobertura.
     *
     * Al crear viaja como parámetro de ruta ({paciente}); al editar la ruta
     * es {cobertura} y el paciente se resuelve desde ese registro. Las dos
     * URLs no comparten forma, así que no alcanza con leer un solo nombre de
     * parámetro.
     */
    private function pacienteId(): ?int
    {
        $paciente = $this->route('paciente');

        if ($paciente instanceof Paciente) {
            return $paciente->id;
        }

        return $this->coberturaDeLaRuta()?->paciente_id;
    }

    private function coberturaDeLaRuta(): ?Cobertura
    {
        $cobertura = $this->route('cobertura');

        return $cobertura instanceof Cobertura ? $cobertura : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipo' => 'tipo de cobertura',
            'entidad' => 'obra social o prepaga',
            'plan' => 'plan',
            'nro_afiliado' => 'número de afiliado',
            'telefono' => 'teléfono',
            'telefono_urgencias' => 'teléfono de urgencias',
            'sitio_web' => 'sitio web',
            'vigencia_desde' => 'vigencia desde',
            'vigencia_hasta' => 'vigencia hasta',
            'notas' => 'notas',
        ];
    }
}
