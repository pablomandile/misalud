<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\AutorizaSobreLaRuta;
use App\Models\CuentaMail;
use App\Rules\IndiceCiegoUnico;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CuentaMailGuardarRequest extends FormRequest
{
    use AutorizaSobreLaRuta;

    /**
     * Autorizar ANTES de validar: sin esto, a un extraño le contestaba la
     * validación y le confirmaba que el registro existe. Ver
     * `AutorizaSobreLaRuta` y `BarridoDePrivacidadTest`.
     */
    public function authorize(): bool
    {
        return $this->puedeGuardar('cuenta', CuentaMail::class);
    }

    /**
     * Los dos puertos de IMAP, y los únicos que se aceptan.
     *
     * No es una lista arbitraria: de esto sale `CuentaMail::encriptacion()`.
     * Aceptar un puerto cualquiera obligaría a preguntar además cómo se cifra
     * el transporte, que es una pregunta que quien configura su casilla no
     * puede contestar.
     *
     * @var list<int>
     */
    private const PUERTOS = [993, 143];

    /**
     * Separa los filtros, que llegan de un `<textarea>`.
     *
     * Un campo de a una línea es lo que una persona puede leer y corregir; un
     * array de inputs dinámicos con botoncitos de "+" y "−" es lo contrario de
     * lo que esta app necesita. El precio es partir el texto acá, **antes** de
     * validar, igual que `NormalizaDecimales` con la coma: si la regla `array`
     * viera el string crudo, rechazaría algo perfectamente escrito.
     *
     * Se parte por líneas **y por comas** porque pegar una lista separada por
     * comas es lo que hace cualquiera, y se normaliza a minúsculas porque un
     * dominio no distingue mayúsculas y dos entradas iguales con distinto case
     * serían dos filtros para lo mismo.
     */
    protected function prepareForValidation(): void
    {
        $crudo = $this->input('filtros');

        if (! is_string($crudo)) {
            return;
        }

        $lineas = preg_split('/[\r\n,]+/', $crudo) ?: [];

        $limpias = array_values(array_unique(array_filter(
            array_map(static fn (string $linea): string => mb_strtolower(trim($linea)), $lineas),
            static fn (string $linea): bool => $linea !== '',
        )));

        $this->merge(['filtros' => $limpias]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * Solo el nombre del servidor. La regex existe para un error
             * concreto y muy frecuente: pegar `imap.gmail.com:993` o
             * `imap://imap.gmail.com` en este campo. Sin ella el guardado
             * anda y lo que falla después es la conexión, con un "no se llega
             * al servidor" que manda a revisar la red en vez del campo.
             */
            'host' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$/'],

            'puerto' => ['required', 'integer', Rule::in(self::PUERTOS)],

            /*
             * Se valida como mail y no como texto libre: en Gmail, Outlook y
             * cualquier casilla de hosting el usuario de IMAP **es** la
             * dirección, así que exigirlo atrapa el tipeo en el campo en vez
             * de en un login rechazado. Si algún día aparece un servidor con
             * un usuario que no es un mail, esta regla es el único lugar que
             * hay que abrir.
             */
            'direccion' => [
                'required',
                'string',
                'email',
                'max:255',
                new IndiceCiegoUnico(
                    CuentaMail::class,
                    'direccion',
                    ['usuario_id' => $this->user()?->id],
                    $this->cuentaDeLaRuta()?->id,
                ),
            ],

            /*
             * ⚠️ Obligatoria al crear, opcional al editar, y esa asimetría es
             * la regla de "la contraseña no viaja al navegador": el formulario
             * de edición no la trae, así que dejarlo vacío significa "dejá la
             * que está". Si fuera `required` siempre, editar la carpeta
             * obligaría a volver a tipear la contraseña de aplicación.
             */
            'password' => [$this->esAlta() ? 'required' : 'nullable', 'string', 'max:255'],

            'carpeta' => ['required', 'string', 'max:255'],

            'filtros' => ['nullable', 'array', 'max:50'],

            /*
             * Una dirección entera (`recetas@farmacia.com.ar`) o un dominio
             * suelto (`farmacia.com.ar`). El dominio importa: una obra social
             * manda desde `noreply@` hoy y desde `avisos@` el mes que viene, y
             * filtrar por dominio sobrevive a eso.
             */
            'filtros.*' => ['string', 'max:255', 'regex:/^(?:[^@\s]+@)?[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}$/'],
        ];
    }

    private function esAlta(): bool
    {
        return $this->cuentaDeLaRuta() === null;
    }

    private function cuentaDeLaRuta(): ?CuentaMail
    {
        $cuenta = $this->route('cuenta');

        return $cuenta instanceof CuentaMail ? $cuenta : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'host' => 'servidor',
            'puerto' => 'puerto',
            'direccion' => 'dirección de correo',
            'password' => 'contraseña',
            'carpeta' => 'carpeta',
            'filtros' => 'filtros',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'host.regex' => 'El servidor va solo con su nombre, como imap.gmail.com: '
                .'sin imap:// adelante y sin el puerto al final.',
            'puerto.in' => 'El puerto es 993 (lo normal) o 143.',
            'filtros.*.regex' => 'Cada línea tiene que ser una dirección de correo '
                .'(recetas@farmacia.com.ar) o un dominio (farmacia.com.ar).',
            'filtros.max' => 'Son demasiados filtros: entran hasta 50.',
        ];
    }
}
