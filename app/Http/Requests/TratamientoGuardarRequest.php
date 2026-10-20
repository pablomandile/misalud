<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PeriodicidadToma;
use App\Models\Medicamento;
use App\Models\Paciente;
use App\Models\Tratamiento;
use App\Services\AdjuntadorDeArchivos;
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

        $this->resolverMedicamentoPorNombre();
    }

    /**
     * El formulario manda el NOMBRE del medicamento, elegido de la lista o
     * escrito a mano. Si coincide con uno que esta persona puede usar (propio
     * primero, después la lista compartida o lo que la ficha ya usa), se
     * convierte acá en su `medicamento_id`, antes de que la regla lo mire. Si
     * no coincide con ninguno, queda sin id y el controlador lo crea en el
     * catálogo propio: nadie tiene que "copiar" una semilla para usarla.
     */
    private function resolverMedicamentoPorNombre(): void
    {
        $nombre = $this->input('medicamento_nombre');

        if (! is_string($nombre) || trim($nombre) === '' || $this->filled('medicamento_id')) {
            return;
        }

        $this->merge(['medicamento_nombre' => trim($nombre)]);

        $usuarioId = $this->user()?->getAuthIdentifier();

        $candidatos = Medicamento::query()
            ->where('nombre_hash', Medicamento::hashCiego($nombre))
            ->where(fn ($ambito) => $ambito
                ->where(CatalogoVisible::para($usuarioId))
                ->orWhereIn('id', $this->medicamentosYaUsadosEnLaFicha())
            )
            ->get(['id', 'usuario_id']);

        $elegido = $candidatos->firstWhere('usuario_id', $usuarioId)
            ?? $candidatos->firstWhere('usuario_id', null)
            ?? $candidatos->first();

        if ($elegido !== null) {
            $this->merge(['medicamento_id' => $elegido->id]);
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
            'medicamento_nombre' => ['nullable', 'string', 'max:255'],
            'medicamento_id' => [
                'required_without:medicamento_nombre',
                'nullable',
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
            'periodicidad' => ['nullable', Rule::enum(PeriodicidadToma::class)],
            // Opcional: una toma única no tiene "cada cuánto".
            'frecuencia' => ['nullable', 'string', 'max:255'],

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

            // Las fotos de la caja, en el mismo alta. Al editar no llegan: la
            // edición va por PUT, donde PHP no parsea el multipart.
            ...AdjuntadorDeArchivos::reglasOpcionales(),
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
            'medicamento_nombre' => 'medicamento',
            'periodicidad' => 'toma',
            'medico_id' => 'médico',
            'enfermedad_id' => 'enfermedad',
            'dosis' => 'dosis',
            'frecuencia' => 'frecuencia',
            'inicio' => 'fecha de inicio',
            'fin' => 'fecha de fin',
            'notas' => 'observaciones',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'medicamento_id.required_without' => 'Elegí el medicamento de la lista o escribí su nombre.',
            'medicamento_id.exists' => 'Elegí un medicamento de tu catálogo.',
            'medico_id.exists' => 'Elegí un médico de tu agenda.',
            'fin.after_or_equal' => 'La fecha de fin no puede ser anterior al inicio.',
        ];
    }
}
