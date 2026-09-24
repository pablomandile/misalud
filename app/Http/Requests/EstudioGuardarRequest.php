<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Estudio;
use App\Models\Paciente;
use App\Models\User;
use App\Rules\FechaNoFutura;
use App\Support\CatalogoVisible;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EstudioGuardarRequest extends FormRequest
{
    /**
     * Autorizar ANTES de validar: si no, a alguien sin permiso le contesta
     * primero la validación y de paso le confirma que la ficha existe.
     */
    public function authorize(): bool
    {
        $estudio = $this->estudioDeLaRuta();

        if ($estudio !== null) {
            return Gate::allows('update', $estudio);
        }

        $paciente = $this->route('paciente');

        return $paciente instanceof Paciente
            && Gate::allows('crearEn', [Estudio::class, $paciente]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $usuario = $this->user();
        $usuarioId = $usuario?->getAuthIdentifier();

        return [
            'tipo' => ['required', 'string', 'max:255'],

            // Un estudio es algo que YA se hizo: no se puede cargar uno de
            // mañana. Fecha de calendario, va por `FechaNoFutura`.
            'fecha' => array_filter([
                'required',
                'date',
                $usuario instanceof User ? new FechaNoFutura($usuario) : null,
            ]),

            /*
             * Mismo criterio de siempre para un catálogo: lo que esta
             * persona puede ver -propio o semilla- o lo que esta ficha ya
             * viene usando (ver `CatalogoVisible`; los paréntesis del OR
             * no sobran).
             */
            'medico_id' => [
                'nullable',
                'integer',
                Rule::exists('medicos', 'id')->where(
                    fn (Builder $consulta) => $consulta
                        ->whereNull('deleted_at')
                        ->where(fn (Builder $ambito) => $ambito
                            ->where(CatalogoVisible::para($usuarioId))
                            ->orWhereIn('id', $this->idsYaUsados('medico_id'))
                        ),
                ),
            ],

            'centro_id' => [
                'nullable',
                'integer',
                Rule::exists('centros', 'id')->where(
                    fn (Builder $consulta) => $consulta
                        ->whereNull('deleted_at')
                        ->where(fn (Builder $ambito) => $ambito
                            ->where(CatalogoVisible::para($usuarioId))
                            ->orWhereIn('id', $this->idsYaUsados('centro_id'))
                        ),
                ),
            ],

            /*
             * Igual que en mediciones y tratamientos: tiene que ser una
             * enfermedad del MISMO paciente.
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

            /*
             * Opcional, y solo tiene efecto al CREAR (ver
             * `EstudioController::store()`): la orden que este estudio
             * resuelve. Tiene que ser del mismo paciente y no estar ya
             * vinculada a otro estudio -una vez cargado el resultado, esa
             * orden deja de estar disponible para vincular de nuevo-.
             */
            'orden_estudio_id' => [
                'nullable',
                'integer',
                Rule::exists('ordenes_estudio', 'id')->where(
                    fn (Builder $consulta) => $consulta
                        ->whereNull('deleted_at')
                        ->where('paciente_id', $this->pacienteId())
                        ->whereNull('estudio_id'),
                ),
            ],

            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function estudioDeLaRuta(): ?Estudio
    {
        $estudio = $this->route('estudio');

        return $estudio instanceof Estudio ? $estudio : null;
    }

    private function pacienteId(): ?int
    {
        $paciente = $this->route('paciente');

        if ($paciente instanceof Paciente) {
            return $paciente->id;
        }

        return $this->estudioDeLaRuta()?->paciente_id;
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
            Estudio::query()
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
            'tipo' => 'tipo de estudio',
            'fecha' => 'fecha',
            'medico_id' => 'médico',
            'centro_id' => 'centro',
            'enfermedad_id' => 'enfermedad',
            'orden_estudio_id' => 'orden',
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
            'centro_id.exists' => 'Elegí un centro de tu catálogo.',
            'orden_estudio_id.exists' => 'Esa orden no está disponible para vincular.',
        ];
    }
}
