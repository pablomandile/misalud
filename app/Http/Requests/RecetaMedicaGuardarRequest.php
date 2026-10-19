<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Paciente;
use App\Models\RecetaMedica;
use App\Models\User;
use App\Rules\FechaNoFutura;
use App\Services\AdjuntadorDeArchivos;
use App\Support\CatalogoVisible;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class RecetaMedicaGuardarRequest extends FormRequest
{
    /**
     * Autorizar ANTES de validar (ver `AutorizaSobreLaRuta`).
     */
    public function authorize(): bool
    {
        $receta = $this->recetaDeLaRuta();

        if ($receta !== null) {
            return Gate::allows('update', $receta);
        }

        $paciente = $this->route('paciente');

        return $paciente instanceof Paciente
            && Gate::allows('crearEn', [RecetaMedica::class, $paciente]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $usuario = $this->user();
        $usuarioId = $usuario?->getAuthIdentifier();

        return [
            /*
             * El medicamento del catálogo o el texto, al menos uno: una receta
             * "de nada" no se puede presentar en ninguna farmacia.
             */
            'medicamento_id' => [
                'nullable',
                'integer',
                'required_without:detalle',
                $this->catalogo('medicamentos', $usuarioId, 'medicamento_id'),
            ],
            'detalle' => ['nullable', 'string', 'max:1000', 'required_without:medicamento_id'],

            // Una receta es un papel ya firmado: no puede ser de mañana.
            'fecha' => array_filter([
                'required',
                'date',
                $usuario instanceof User ? new FechaNoFutura($usuario) : null,
            ]),

            // Se rechaza lo que no puede existir: la de un crónico llega a 90 días.
            'vigencia_dias' => ['required', 'integer', 'min:1', 'max:365'],

            'medico_id' => [
                'nullable',
                'integer',
                $this->catalogo('medicos', $usuarioId, 'medico_id'),
            ],

            'notas' => ['nullable', 'string', 'max:2000'],

            // El papel en el mismo alta, opcional (ver AdjuntadorDeArchivos).
            ...AdjuntadorDeArchivos::reglasOpcionales(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'medicamento_id.required_without' => 'Elegí el medicamento o escribí qué te recetaron.',
            'detalle.required_without' => 'Elegí el medicamento o escribí qué te recetaron.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'archivos.*' => 'archivo',
            'medicamento_id' => 'medicamento',
            'detalle' => 'qué te recetaron',
            'fecha' => 'fecha',
            'vigencia_dias' => 'días de validez',
            'medico_id' => 'médico',
            'notas' => 'notas',
        ];
    }

    public function recetaDeLaRuta(): ?RecetaMedica
    {
        $receta = $this->route('recetaMedica');

        return $receta instanceof RecetaMedica ? $receta : null;
    }

    /**
     * Lo de siempre para un catálogo: lo que esta persona ve -propio o
     * semilla- o lo que esta ficha ya viene usando (ver `CatalogoVisible`; el
     * paréntesis del OR no sobra, y `Rule::exists` no filtra la papelera).
     */
    private function catalogo(string $tabla, mixed $usuarioId, string $columna): Exists
    {
        return Rule::exists($tabla, 'id')->where(
            fn (Builder $consulta) => $consulta
                ->whereNull('deleted_at')
                ->where(fn (Builder $ambito) => $ambito
                    ->where(CatalogoVisible::para($usuarioId))
                    ->orWhereIn('id', $this->yaUsadosEnLaFicha($columna))
                ),
        );
    }

    /**
     * @return list<int>
     */
    private function yaUsadosEnLaFicha(string $columna): array
    {
        $paciente = $this->route('paciente');
        $pacienteId = $paciente instanceof Paciente ? $paciente->id : $this->recetaDeLaRuta()?->paciente_id;

        if ($pacienteId === null) {
            return [];
        }

        return array_values(array_map(
            'intval',
            RecetaMedica::query()
                ->where('paciente_id', $pacienteId)
                ->whereNotNull($columna)
                ->pluck($columna)
                ->unique()
                ->all(),
        ));
    }
}
