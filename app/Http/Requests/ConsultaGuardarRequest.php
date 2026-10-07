<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Consulta;
use App\Models\Paciente;
use App\Models\User;
use App\Support\CatalogoVisible;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ConsultaGuardarRequest extends FormRequest
{
    /**
     * Margen para el reloj del dispositivo, igual que una medición: rechazar
     * "ahora" por dos minutos de diferencia sería incomprensible.
     */
    private const GRACIA_MINUTOS = 5;

    /**
     * Autorizar ANTES de validar: si no, a alguien sin permiso le contesta
     * primero la validación y de paso le confirma que la ficha existe.
     */
    public function authorize(): bool
    {
        $consulta = $this->consultaDeLaRuta();

        if ($consulta !== null) {
            return Gate::allows('update', $consulta);
        }

        $paciente = $this->route('paciente');

        return $paciente instanceof Paciente
            && Gate::allows('crearEn', [Consulta::class, $paciente]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $usuarioId = $this->user()?->getAuthIdentifier();

        return [
            /*
             * Registra una visita que YA PASÓ: lo que se agenda es un turno. Al
             * revés que un turno, rechaza el futuro, con el mismo margen que una
             * medición para el reloj del celular.
             */
            'fecha_hora' => ['required', 'date', $this->noPuedeSerFutura()],

            'medico_id' => $this->deCatalogo('medicos', $usuarioId),
            'centro_id' => $this->deCatalogo('centros', $usuarioId),

            // Tiene que ser del MISMO paciente: un id de otra ficha colgaría la
            // consulta de una enfermedad ajena y nada lo delataría en pantalla.
            'enfermedad_id' => $this->deLaFicha('enfermedades'),
            'turno_id' => $this->deLaFicha('turnos'),

            'motivo' => ['nullable', 'string', 'max:500'],
            'notas' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * La fecha y hora ya en UTC, desde la zona de quien la cargó. Acá y no en el
     * controlador para que sea imposible olvidarla (ver "Fechas" en CLAUDE.md).
     */
    public function fechaHoraEnUtc(): ?CarbonImmutable
    {
        $usuario = $this->user();
        $valor = $this->validated('fecha_hora');

        return $usuario instanceof User && is_string($valor)
            ? $usuario->aUtc($valor)
            : null;
    }

    public function consultaDeLaRuta(): ?Consulta
    {
        $consulta = $this->route('consulta');

        return $consulta instanceof Consulta ? $consulta : null;
    }

    /**
     * Propio, semilla o **ya usado en esta ficha** (ver "Una ficha compartida
     * comparte su vocabulario"). ⚠️ Los paréntesis no sobran: ver `CatalogoVisible`.
     *
     * @return array<int, mixed>
     */
    private function deCatalogo(string $tabla, mixed $usuarioId): array
    {
        $columna = $tabla === 'medicos' ? 'medico_id' : 'centro_id';

        return [
            'nullable',
            'integer',
            Rule::exists($tabla, 'id')->where(
                fn (Builder $consulta) => $consulta
                    ->whereNull('deleted_at')
                    ->where(fn (Builder $ambito) => $ambito
                        ->where(CatalogoVisible::para($usuarioId))
                        ->orWhereIn('id', $this->idsYaUsados($columna))
                    ),
            ),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function deLaFicha(string $tabla): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists($tabla, 'id')->where(
                fn (Builder $consulta) => $consulta
                    ->whereNull('deleted_at')
                    ->where('paciente_id', $this->pacienteId()),
            ),
        ];
    }

    private function pacienteId(): ?int
    {
        $paciente = $this->route('paciente');

        if ($paciente instanceof Paciente) {
            return $paciente->id;
        }

        return $this->consultaDeLaRuta()?->paciente_id;
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
            Consulta::query()
                ->where('paciente_id', $pacienteId)
                ->whereNotNull($columna)
                ->pluck($columna)
                ->unique()
                ->all(),
        ));
    }

    /**
     * @return Closure(string, mixed, Closure): void
     */
    private function noPuedeSerFutura(): Closure
    {
        return function (string $atributo, mixed $valor, Closure $fallar): void {
            $usuario = $this->user();

            if (! $usuario instanceof User || ! is_string($valor)) {
                return;
            }

            $instante = $usuario->aUtc($valor);

            if ($instante !== null && $instante->greaterThan(CarbonImmutable::now()->addMinutes(self::GRACIA_MINUTOS))) {
                $fallar('Una consulta que todavía no pasó es un turno: cargala en Turnos.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'fecha_hora' => 'fecha y hora',
            'medico_id' => 'médico',
            'centro_id' => 'centro',
            'enfermedad_id' => 'enfermedad',
            'turno_id' => 'turno',
            'motivo' => 'motivo',
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
            'centro_id.exists' => 'Elegí un centro de tu lista.',
        ];
    }
}
