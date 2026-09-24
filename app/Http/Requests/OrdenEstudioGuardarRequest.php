<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EstadoOrdenEstudio;
use App\Models\OrdenEstudio;
use App\Models\Paciente;
use App\Models\User;
use App\Rules\FechaNoFutura;
use App\Support\CatalogoVisible;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrdenEstudioGuardarRequest extends FormRequest
{
    /**
     * Autorizar ANTES de validar: si no, a alguien sin permiso le contesta
     * primero la validación y de paso le confirma que la ficha existe.
     */
    public function authorize(): bool
    {
        $orden = $this->ordenDeLaRuta();

        if ($orden !== null) {
            return Gate::allows('update', $orden);
        }

        $paciente = $this->route('paciente');

        return $paciente instanceof Paciente
            && Gate::allows('crearEn', [OrdenEstudio::class, $paciente]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $usuario = $this->user();

        return [
            'estudio_solicitado' => ['required', 'string', 'max:255'],

            /*
             * Una orden es un papel que ya existe: no se puede tener una
             * firmada mañana. Fecha de CALENDARIO, así que va por
             * `FechaNoFutura` -que compara con `hoyCalendario()`- y no con
             * la regla de instantes de las mediciones.
             */
            'fecha' => array_filter([
                'required',
                'date',
                $usuario instanceof User ? new FechaNoFutura($usuario) : null,
            ]),

            'estado' => ['required', Rule::enum(EstadoOrdenEstudio::class)],

            /*
             * Mismo criterio de siempre para un catálogo: lo que esta
             * persona puede ver -propio o semilla- o lo que esta ficha ya
             * viene usando (ver `CatalogoVisible`; los paréntesis del OR no
             * sobran).
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

    public function ordenDeLaRuta(): ?OrdenEstudio
    {
        $orden = $this->route('orden');

        return $orden instanceof OrdenEstudio ? $orden : null;
    }

    private function pacienteId(): ?int
    {
        $paciente = $this->route('paciente');

        if ($paciente instanceof Paciente) {
            return $paciente->id;
        }

        return $this->ordenDeLaRuta()?->paciente_id;
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
            OrdenEstudio::query()
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
            'estudio_solicitado' => 'estudio solicitado',
            'fecha' => 'fecha',
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
