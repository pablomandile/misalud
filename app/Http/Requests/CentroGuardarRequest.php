<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\AutorizaSobreLaRuta;
use App\Enums\TipoCentro;
use App\Models\Centro;
use App\Rules\IndiceCiegoUnico;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CentroGuardarRequest extends FormRequest
{
    use AutorizaSobreLaRuta;

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
     * La web se escribe como se la lee ("www.hospital.com.ar"), sin el
     * `https://` que la regla `url` exige: se lo agrega acá, ANTES de validar,
     * o la regla rechazaría lo que la persona escribió bien. El correo, sin
     * espacios y en minúsculas.
     */
    protected function prepareForValidation(): void
    {
        $web = $this->input('web');
        $email = $this->input('email');

        if (is_string($web)) {
            $web = trim($web);
            $web = $web === '' ? null : (preg_match('#^https?://#i', $web) ? $web : 'https://'.$web);
        }

        $this->merge([
            'web' => $web,
            'email' => is_string($email) ? (mb_strtolower(trim($email)) ?: null) : $email,
        ]);
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
                    ['usuario_id' => auth()->id()],
                    $this->centroDeLaRuta()?->id,
                ),
            ],
            'tipo' => ['required', Rule::enum(TipoCentro::class)],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'whatsapp' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\s.\-]+$/'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'web' => ['nullable', 'string', 'url:http,https', 'max:255'],
            'horarios' => ['nullable', 'string', 'max:1000'],
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
            'whatsapp' => 'WhatsApp',
            'email' => 'correo electrónico',
            'web' => 'página web',
            'horarios' => 'horarios de atención',
            'notas' => 'notas',
            'medicos' => 'médicos',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'whatsapp.regex' => 'El WhatsApp solo puede tener números, espacios, guiones, paréntesis y el +.',
            'web.url' => 'Escribí la dirección de la página, por ejemplo www.hospital.com.ar.',
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
