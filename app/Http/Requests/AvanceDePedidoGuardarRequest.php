<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\AutorizaSobreLaRuta;
use App\Models\Tratamiento;
use App\Models\User;
use App\Rules\FechaNoFutura;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El avance del pedido en curso de un medicamento: cuándo empezó y, por cada
 * etapa, si se completó, cuándo y qué pasó.
 */
class AvanceDePedidoGuardarRequest extends FormRequest
{
    use AutorizaSobreLaRuta;

    // Autorizar ANTES de validar (ver AutorizaSobreLaRuta).
    public function authorize(): bool
    {
        return $this->puedeGuardar('tratamiento', Tratamiento::class);
    }

    /**
     * ⚠️ Un checkbox tildado manda `"on"`, que la regla `boolean` rechaza: se
     * normaliza antes. Y la fecha de una etapa sin tildar se descarta: oculta
     * en pantalla, puede viajar igual.
     */
    protected function prepareForValidation(): void
    {
        $pasos = $this->input('pasos');

        if (! is_array($pasos)) {
            return;
        }

        $this->merge([
            'pasos' => array_map(function (mixed $paso): mixed {
                if (! is_array($paso)) {
                    return $paso;
                }

                $completada = filter_var($paso['completada'] ?? false, FILTER_VALIDATE_BOOLEAN);

                return [
                    ...$paso,
                    'completada' => $completada,
                    'completada_el' => $completada ? ($paso['completada_el'] ?? null) : null,
                ];
            }, $pasos),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User $usuario */
        $usuario = $this->user();
        $tratamiento = $this->route('tratamiento');
        assert($tratamiento instanceof Tratamiento);

        return [
            'iniciado_el' => ['required', 'date', new FechaNoFutura($usuario)],
            'pasos' => ['required', 'array', 'min:1'],
            'pasos.*.etapa_id' => ['required', 'integer', 'distinct', Rule::in($tratamiento->etapasProcedimiento()->pluck('id')->all())],
            'pasos.*.completada' => ['required', 'boolean'],
            'pasos.*.completada_el' => ['nullable', 'required_if:pasos.*.completada,true', 'date', 'after_or_equal:iniciado_el', new FechaNoFutura($usuario)],
            'pasos.*.observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'iniciado_el.required' => 'Elegí la fecha en que empezó el pedido.',
            'pasos.required' => 'Primero armá las etapas del procedimiento.',
            'pasos.*.etapa_id.in' => 'Esa etapa ya no está en el procedimiento: recargá la pantalla.',
            'pasos.*.completada_el.required_if' => 'Elegí la fecha en que terminó esta etapa.',
            'pasos.*.completada_el.after_or_equal' => 'No puede terminar antes de que empiece el pedido.',
            'pasos.*.observaciones.max' => 'Las observaciones son muy largas.',
        ];
    }
}
