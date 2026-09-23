<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TipoCentro;
use App\Models\Centro;
use App\Rules\IndiceCiegoUnico;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CentroGuardarRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nombre' => [
                'required',
                'string',
                'max:255',
                new IndiceCiegoUnico(
                    Centro::class,
                    'nombre',
                    ['usuario_id' => auth()->id()],
                    $this->centroDeLaRuta()?->id,
                ),
            ],
            'tipo' => ['required', Rule::enum(TipoCentro::class)],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'notas' => ['nullable', 'string', 'max:2000'],

            'medicos' => ['nullable', 'array'],
            /*
             * `exists` con una acotación por cierre: solo médicos VISIBLES
             * para este usuario -los propios más las semillas-, nunca los de
             * otro. El paréntesis del `where` no es cosmético: sin agrupar el
             * `orWhereNull`, se mezcla con la condición de `id` que ya agrega
             * `exists` y la regla deja pasar cualquier médico que exista, sin
             * importar de quién sea. Es el mismo error que ya se documentó en
             * `visiblesPara()`, acá disfrazado de regla de validación.
             */
            'medicos.*' => [
                'integer',
                Rule::exists('medicos', 'id')->where(
                    fn ($consulta) => $consulta->where(function ($acotada): void {
                        $acotada->where('usuario_id', auth()->id())->orWhereNull('usuario_id');
                    }),
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => 'nombre',
            'tipo' => 'tipo de centro',
            'direccion' => 'dirección',
            'telefono' => 'teléfono',
            'notas' => 'notas',
            'medicos' => 'médicos',
        ];
    }

    /**
     * @return list<int>
     */
    public function medicosIds(): array
    {
        return array_values(array_map('intval', $this->input('medicos', [])));
    }

    private function centroDeLaRuta(): ?Centro
    {
        $centro = $this->route('centro');

        return $centro instanceof Centro ? $centro : null;
    }
}
