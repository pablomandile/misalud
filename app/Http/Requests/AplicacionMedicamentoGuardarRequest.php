<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PeriodicidadToma;
use App\Models\AplicacionMedicamento;
use App\Models\Paciente;
use App\Models\User;
use App\Rules\FechaNoFutura;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AplicacionMedicamentoGuardarRequest extends FormRequest
{
    /**
     * Autorizar ANTES de validar: si no, a alguien sin permiso le contesta
     * primero la validación y de paso le confirma que la ficha existe.
     */
    public function authorize(): bool
    {
        $aplicacion = $this->aplicacionDeLaRuta();

        if ($aplicacion !== null) {
            return Gate::allows('update', $aplicacion);
        }

        $paciente = $this->route('paciente');

        return $paciente instanceof Paciente
            && Gate::allows('crearEn', [AplicacionMedicamento::class, $paciente]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User $usuario */
        $usuario = $this->user();

        return [
            /*
             * Un medicamento de ESTA ficha, y que no sea de todos los días: una
             * toma diaria no se anota una por una. Sin la condición de la ficha,
             * un id ajeno colgaría la aplicación de un medicamento de otra persona.
             */
            'tratamiento_id' => [
                'required',
                'integer',
                Rule::exists('tratamientos', 'id')->where(
                    fn (Builder $consulta) => $consulta
                        ->whereNull('deleted_at')
                        ->where('paciente_id', $this->pacienteId())
                        ->where(fn (Builder $toma) => $toma
                            ->whereNull('periodicidad')
                            ->orWhere('periodicidad', '!=', PeriodicidadToma::Diaria->value)),
                ),
            ],

            // De la misma ficha, por lo mismo.
            'enfermedad_id' => [
                'nullable',
                'integer',
                Rule::exists('enfermedades', 'id')->where(
                    fn (Builder $consulta) => $consulta
                        ->whereNull('deleted_at')
                        ->where('paciente_id', $this->pacienteId()),
                ),
            ],

            // Registra algo que ya pasó.
            'fecha' => ['required', 'date', new FechaNoFutura($usuario)],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function aplicacionDeLaRuta(): ?AplicacionMedicamento
    {
        $aplicacion = $this->route('aplicacionMedicamento');

        return $aplicacion instanceof AplicacionMedicamento ? $aplicacion : null;
    }

    private function pacienteId(): ?int
    {
        $paciente = $this->route('paciente');

        if ($paciente instanceof Paciente) {
            return $paciente->id;
        }

        return $this->aplicacionDeLaRuta()?->paciente_id;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tratamiento_id' => 'medicamento',
            'enfermedad_id' => 'enfermedad',
            'fecha' => 'fecha',
            'notas' => 'observaciones',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tratamiento_id.required' => 'Elegí el medicamento.',
            'tratamiento_id.exists' => 'Elegí uno de los medicamentos de esta ficha que no son de todos los días.',
            'enfermedad_id.exists' => 'Elegí una enfermedad de esta ficha.',
        ];
    }
}
