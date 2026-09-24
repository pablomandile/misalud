<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Paciente;
use App\Models\Tratamiento;
use App\Support\CatalogoVisible;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TratamientoGuardarRequest extends FormRequest
{
    /**
     * Autorizar ANTES de validar: si no, a alguien sin permiso le contesta
     * primero la validación y de paso le confirma que la ficha existe.
     */
    public function authorize(): bool
    {
        $tratamiento = $this->tratamientoDeLaRuta();

        if ($tratamiento !== null) {
            return Gate::allows('update', $tratamiento);
        }

        $paciente = $this->route('paciente');

        return $paciente instanceof Paciente
            && Gate::allows('crearEn', [Tratamiento::class, $paciente]);
    }

    /**
     * Normaliza el checkbox de "activo" ANTES de validar.
     *
     * Un `<input type="checkbox">` tildado manda `"on"`, y `boolean` lo
     * rechaza -ver la regla ya documentada en la cobertura médica-. Se
     * normaliza acá, y no en el controlador, que ya sería tarde.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('activo')) {
            $this->merge(['activo' => $this->boolean('activo')]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $usuarioId = $this->user()?->getAuthIdentifier();

        return [
            /*
             * El medicamento tiene que ser uno que esta persona pueda VER
             * -propio o semilla- **o uno que esta ficha ya esté usando**.
             * Mismo criterio que el médico de una enfermedad y el tipo de
             * una medición, y por el mismo motivo: los catálogos son del
             * usuario y una ficha compartida la escriben varios.
             *
             * ⚠️ Los paréntesis no sobran: un OR sin agrupar se mezcla con
             * la condición de `id` que agrega `exists` y deja pasar
             * cualquier medicamento (ver `CatalogoVisible`).
             */
            'medicamento_id' => [
                'required',
                'integer',
                Rule::exists('medicamentos', 'id')->where(
                    fn (Builder $consulta) => $consulta
                        ->whereNull('deleted_at')
                        ->where(fn (Builder $ambito) => $ambito
                            ->where(CatalogoVisible::para($usuarioId))
                            ->orWhereIn('id', $this->medicamentosYaUsadosEnLaFicha())
                        ),
                ),
            ],

            'medico_id' => [
                'nullable',
                'integer',
                Rule::exists('medicos', 'id')->where(
                    fn (Builder $consulta) => $consulta
                        ->whereNull('deleted_at')
                        ->where(fn (Builder $ambito) => $ambito
                            ->where(CatalogoVisible::para($usuarioId))
                            ->orWhereIn('id', $this->medicosYaUsadosEnLaFicha())
                        ),
                ),
            ],

            /*
             * Igual que en una medición: tiene que ser una enfermedad del
             * MISMO paciente. Sin esa condición, un id de otra ficha
             * vincularía un tratamiento ajeno a una enfermedad de acá.
             */
            'enfermedad_id' => [
                'nullable',
                'integer',
                Rule::exists('enfermedades', 'id')->where(
                    fn (Builder $consulta) => $consulta
                        ->whereNull('deleted_at')
                        ->where('paciente_id', $this->pacienteId()),
                ),
            ],

            'dosis' => ['required', 'string', 'max:255'],
            'frecuencia' => ['required', 'string', 'max:255'],

            'inicio' => ['required', 'date'],

            /*
             * Sin `FechaNoFutura`: un tratamiento se puede cargar para
             * empezar mañana -el médico lo indicó para después de terminar
             * otro-, al revés que una fecha de diagnóstico, que siempre es
             * del pasado.
             */
            'fin' => ['nullable', 'date', 'after_or_equal:inicio'],

            'activo' => ['nullable', 'boolean'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function tratamientoDeLaRuta(): ?Tratamiento
    {
        $tratamiento = $this->route('tratamiento');

        return $tratamiento instanceof Tratamiento ? $tratamiento : null;
    }

    private function pacienteId(): ?int
    {
        $paciente = $this->route('paciente');

        if ($paciente instanceof Paciente) {
            return $paciente->id;
        }

        return $this->tratamientoDeLaRuta()?->paciente_id;
    }

    /**
     * @return list<int>
     */
    private function medicamentosYaUsadosEnLaFicha(): array
    {
        return $this->idsYaUsados('medicamento_id');
    }

    /**
     * @return list<int>
     */
    private function medicosYaUsadosEnLaFicha(): array
    {
        return $this->idsYaUsados('medico_id');
    }

    /**
     * @return list<int>
     */
    private function idsYaUsados(string $columna): array
    {
        $pacienteId = $this->pacienteId();

        if ($pacienteId === null) {
            return [];
        }

        return array_values(array_map(
            'intval',
            Tratamiento::query()
                ->where('paciente_id', $pacienteId)
                ->whereNotNull($columna)
                ->pluck($columna)
                ->unique()
                ->all(),
        ));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'medicamento_id' => 'medicamento',
            'medico_id' => 'médico',
            'enfermedad_id' => 'enfermedad',
            'dosis' => 'dosis',
            'frecuencia' => 'frecuencia',
            'inicio' => 'fecha de inicio',
            'fin' => 'fecha de fin',
            'notas' => 'notas',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'medicamento_id.exists' => 'Elegí un medicamento de tu catálogo.',
            'medico_id.exists' => 'Elegí un médico de tu agenda.',
            'fin.after_or_equal' => 'La fecha de fin no puede ser anterior al inicio.',
        ];
    }
}
