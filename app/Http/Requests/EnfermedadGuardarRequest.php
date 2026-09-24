<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EstadoEnfermedad;
use App\Models\Enfermedad;
use App\Models\Paciente;
use App\Models\User;
use App\Rules\FechaNoFutura;
use App\Support\CatalogoVisible;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EnfermedadGuardarRequest extends FormRequest
{
    /**
     * Autorizar ANTES de validar: si no, a alguien sin permiso le contesta
     * primero la validación y de paso le confirma que la ficha existe.
     */
    public function authorize(): bool
    {
        $enfermedad = $this->enfermedadDeLaRuta();

        if ($enfermedad !== null) {
            return Gate::allows('update', $enfermedad);
        }

        $paciente = $this->route('paciente');

        return $paciente instanceof Paciente
            && Gate::allows('crearEn', [Enfermedad::class, $paciente]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $usuario = $this->user();

        return [
            'nombre' => ['required', 'string', 'max:255'],

            'fecha_diagnostico' => array_filter([
                'nullable',
                'date',
                $usuario instanceof User ? new FechaNoFutura($usuario) : null,
            ]),

            'estado' => ['required', Rule::enum(EstadoEnfermedad::class)],

            /*
             * El médico es opcional, y si viene tiene que ser uno que esta
             * persona pueda VER -propio o semilla- **o uno que esta ficha ya
             * esté usando**. La segunda mitad es la misma regla que en
             * mediciones y por el mismo motivo: los catálogos son del
             * usuario y una ficha compartida la escriben varios. Sin ella,
             * un cuidador que edita las notas de una enfermedad que cargó
             * el dueño vería rechazado el médico que ya estaba puesto.
             *
             * ⚠️ Los paréntesis no sobran: un OR sin agrupar se mezcla con
             * la condición de `id` que agrega `exists` y deja pasar
             * cualquier médico (ver `CatalogoVisible`).
             */
            'medico_id' => [
                'nullable',
                'integer',
                Rule::exists('medicos', 'id')->where(
                    fn (Builder $consulta) => $consulta
                        ->whereNull('deleted_at')
                        ->where(fn (Builder $ambito) => $ambito
                            ->where(CatalogoVisible::para($usuario?->getAuthIdentifier()))
                            ->orWhereIn('id', $this->medicosYaUsadosEnLaFicha())
                        ),
                ),
            ],

            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function enfermedadDeLaRuta(): ?Enfermedad
    {
        $enfermedad = $this->route('enfermedad');

        return $enfermedad instanceof Enfermedad ? $enfermedad : null;
    }

    private function pacienteId(): ?int
    {
        $paciente = $this->route('paciente');

        if ($paciente instanceof Paciente) {
            return $paciente->id;
        }

        return $this->enfermedadDeLaRuta()?->paciente_id;
    }

    /**
     * @return list<int>
     */
    private function medicosYaUsadosEnLaFicha(): array
    {
        $pacienteId = $this->pacienteId();

        if ($pacienteId === null) {
            return [];
        }

        return array_values(array_map(
            'intval',
            Enfermedad::query()
                ->where('paciente_id', $pacienteId)
                ->whereNotNull('medico_id')
                ->pluck('medico_id')
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
            'nombre' => 'nombre',
            'fecha_diagnostico' => 'fecha de diagnóstico',
            'estado' => 'estado',
            'medico_id' => 'médico',
            'notas' => 'notas',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'medico_id.exists' => 'Elegí un médico de tu agenda.',
        ];
    }
}
