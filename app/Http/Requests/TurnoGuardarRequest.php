<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EstadoTurno;
use App\Models\Paciente;
use App\Models\Turno;
use App\Models\User;
use App\Support\CatalogoVisible;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TurnoGuardarRequest extends FormRequest
{
    /**
     * Autorizar ANTES de validar: si no, a alguien sin permiso le contesta
     * primero la validación y de paso le confirma que la ficha existe.
     */
    public function authorize(): bool
    {
        $turno = $this->turnoDeLaRuta();

        if ($turno !== null) {
            return Gate::allows('update', $turno);
        }

        $paciente = $this->route('paciente');

        return $paciente instanceof Paciente
            && Gate::allows('crearEn', [Turno::class, $paciente]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $usuarioId = $this->user()?->getAuthIdentifier();

        return [
            /*
             * ⚠️ **Sin ninguna regla de rango, y es la decisión del paso.**
             * Un turno se agenda para el futuro -ese es el caso normal- y
             * también se carga hacia atrás, para registrar que se fue. Es lo
             * contrario de una medición, que rechaza el futuro porque
             * registra algo que ya pasó. Poner acá una `FechaNoFutura` por
             * simetría con el resto de las fechas de la app haría imposible
             * usar la pantalla para lo que existe.
             */
            'fecha_hora' => ['required', 'date'],

            'estado' => ['required', Rule::enum(EstadoTurno::class)],

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
             * La orden que este turno viene a resolver. Tiene que ser del
             * MISMO paciente -sin eso, un id ajeno colgaría el turno de una
             * orden de otra ficha y nada lo delataría en pantalla-.
             *
             * **No se exige que esté pendiente**, a propósito: un turno de
             * control por un estudio ya hecho es un caso real, y rechazarlo
             * sería inventar una regla que el consultorio no tiene.
             */
            'orden_estudio_id' => [
                'nullable',
                'integer',
                Rule::exists('ordenes_estudio', 'id')->where(
                    fn (Builder $consulta) => $consulta
                        ->whereNull('deleted_at')
                        ->where('paciente_id', $this->pacienteId()),
                ),
            ],

            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * La fecha y hora ya convertidas a UTC desde la zona de quien la cargó.
     *
     * Vive acá y no en el controlador **para que sea imposible olvidarla en
     * una acción nueva**: lo que manda un `datetime-local` no trae zona, y
     * guardarlo tal cual corre el turno tantas horas como diga el huso, sin
     * ningún síntoma hasta que alguien mira la hora. Mismo patrón que
     * `MedicionGuardarRequest::fechaEnUtc()`, que es el otro `datetime` que
     * carga una persona en esta app.
     */
    public function fechaHoraEnUtc(): ?CarbonImmutable
    {
        $usuario = $this->user();
        $valor = $this->validated('fecha_hora');

        return $usuario instanceof User && is_string($valor)
            ? $usuario->aUtc($valor)
            : null;
    }

    public function turnoDeLaRuta(): ?Turno
    {
        $turno = $this->route('turno');

        return $turno instanceof Turno ? $turno : null;
    }

    private function pacienteId(): ?int
    {
        $paciente = $this->route('paciente');

        if ($paciente instanceof Paciente) {
            return $paciente->id;
        }

        return $this->turnoDeLaRuta()?->paciente_id;
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
            Turno::query()
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
            'fecha_hora' => 'fecha y hora',
            'estado' => 'estado',
            'medico_id' => 'médico',
            'centro_id' => 'centro',
            'orden_estudio_id' => 'orden de estudio',
            'motivo' => 'motivo',
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
            'orden_estudio_id.exists' => 'Esa orden no es de esta ficha.',
        ];
    }
}
