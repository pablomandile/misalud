<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Paciente;
use App\Models\User;
use App\Services\LibretaDeDestinatarios;
use App\Support\Destinatario;
use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PedidoMedicamentosGuardarRequest extends FormRequest
{
    /** A quién se le puede pedir: del catálogo, no los contactos de la libreta. */
    public const ORIGENES = ['medico', 'centro', 'farmacia'];

    /**
     * Autorizar ANTES de validar, y pidiendo `update`: mandar el pedido guarda la
     * plantilla en la ficha. Una ficha que no existe contesta igual que una ajena.
     */
    public function authorize(): bool
    {
        $paciente = $this->paciente();

        return $paciente !== null && Gate::allows('update', $paciente);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'paciente_id' => ['required', 'integer'],

            // Medicamentos de ESTA ficha: un id ajeno pondría en el mail lo que toma otra persona.
            'tratamientos' => ['required', 'array', 'min:1'],
            'tratamientos.*' => [
                'integer',
                Rule::exists('tratamientos', 'id')->where(
                    fn (Builder $consulta) => $consulta
                        ->whereNull('deleted_at')
                        ->where('paciente_id', $this->paciente()?->id),
                ),
            ],

            // Una clave de la lista, nunca una dirección (ver LibretaDeDestinatarios).
            'destinatario' => [
                'required',
                'string',
                function (string $atributo, mixed $clave, Closure $fallar): void {
                    if ($this->destinatario() === null) {
                        $fallar('Elegí un médico, un centro o una farmacia de la lista.');
                    }
                },
            ],

            'mensaje' => ['required', 'string', 'max:2000'],
        ];
    }

    public function paciente(): ?Paciente
    {
        $id = $this->input('paciente_id');

        return is_numeric($id) ? Paciente::query()->find((int) $id) : null;
    }

    public function destinatario(): ?Destinatario
    {
        $usuario = $this->user();
        $clave = $this->input('destinatario');

        if (! $usuario instanceof User || ! is_string($clave)) {
            return null;
        }

        $destinatario = app(LibretaDeDestinatarios::class)->resolver($usuario, $clave);

        return $destinatario !== null && in_array($destinatario->origen, self::ORIGENES, true)
            ? $destinatario
            : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tratamientos' => 'medicamentos',
            'destinatario' => 'a quién',
            'mensaje' => 'mensaje',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tratamientos.required' => 'Elegí al menos un medicamento.',
            'tratamientos.*.exists' => 'Elegí medicamentos de esta ficha.',
            'destinatario.required' => 'Elegí a quién mandarle el pedido.',
        ];
    }
}
