<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\AutorizaSobreLaRuta;
use App\Concerns\CatalogoDeLaRuta;
use App\Concerns\ValidaDatosDeContacto;
use App\Enums\TipoCentro;
use App\Models\Centro;
use App\Rules\IndiceCiegoUnico;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CentroGuardarRequest extends FormRequest
{
    use AutorizaSobreLaRuta;
    use CatalogoDeLaRuta;
    use ValidaDatosDeContacto;

    /**
     * Autorizar ANTES de validar: sin esto, a un extraño le contestaba la
     * validación y le confirmaba que el registro existe. Ver
     * `AutorizaSobreLaRuta` y `BarridoDePrivacidadTest`.
     */
    public function authorize(): bool
    {
        return $this->puedeGuardar('centro', Centro::class);
    }

    /**
     * La web sin `https://` y el correo en minúsculas, antes de validar (ver
     * `ValidaDatosDeContacto`).
     */
    protected function prepareForValidation(): void
    {
        $this->normalizarDatosDeContacto();
    }

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
                    ['usuario_id' => $this->duenioDelCatalogo('centro')],
                    $this->centroDeLaRuta()?->id,
                ),
            ],
            'tipo' => ['required', Rule::enum(TipoCentro::class)],
            ...$this->reglasDeContacto(),
            'notas' => ['nullable', 'string', 'max:2000'],

            'medicos' => ['nullable', 'array'],
            /*
             * `exists` con una acotación por cierre: solo médicos del MISMO
             * catálogo que el centro -los de su dueño más las semillas-, nunca
             * los de otro. Al editar el centro del dueño de una ficha que se
             * cuida, son los médicos del dueño: vincularle uno del cuidador
             * dejaría en su lista un médico que él no ve. El paréntesis del `where` no es cosmético: sin agrupar el
             * `orWhereNull`, se mezcla con la condición de `id` que ya agrega
             * `exists` y la regla deja pasar cualquier médico que exista, sin
             * importar de quién sea. Es el mismo error que ya se documentó en
             * `visiblesPara()`, acá disfrazado de regla de validación.
             */
            'medicos.*' => [
                'integer',
                Rule::exists('medicos', 'id')->where(
                    fn ($consulta) => $consulta->where(function ($acotada): void {
                        $acotada->where('usuario_id', $this->duenioDelCatalogo('centro'))->orWhereNull('usuario_id');
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
            ...$this->atributosDeContacto(),
            'notas' => 'notas',
            'medicos' => 'médicos',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->mensajesDeContacto();
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
