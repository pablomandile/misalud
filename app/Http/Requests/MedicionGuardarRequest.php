<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\NormalizaDecimales;
use App\Models\Medicion;
use App\Models\Paciente;
use App\Models\TipoMedicion;
use App\Models\User;
use App\Support\CatalogoVisible;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MedicionGuardarRequest extends FormRequest
{
    use NormalizaDecimales;

    /**
     * Cuánto se le perdona al reloj del dispositivo.
     *
     * Una medición es un registro de algo que ya pasó, así que el futuro se
     * rechaza. Pero el reloj de un celular puede adelantar un minuto, y
     * rechazar "ahora" por dos segundos de diferencia sería un error
     * incomprensible para quien acaba de tomarse la presión.
     */
    private const GRACIA_MINUTOS = 5;

    /**
     * Autorizar ANTES de validar.
     *
     * Sin esto, a alguien sin permiso le contesta primero la validación:
     * en vez de un 403 recibe "Elegí una variable de tu lista", que además
     * de confuso le confirma que la ficha existe. `authorize()` del
     * FormRequest corre antes que `rules()`, así que es el lugar.
     */
    public function authorize(): bool
    {
        $medicion = $this->medicionDeLaRuta();

        if ($medicion !== null) {
            return Gate::allows('update', $medicion);
        }

        $paciente = $this->route('paciente');

        return $paciente instanceof Paciente
            && Gate::allows('crearEn', [Medicion::class, $paciente]);
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarDecimales(['valor', 'valor_secundario']);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tipo = $this->tipo();
        $tieneSecundario = $tipo?->tieneValorSecundario() ?? false;

        return [
            'tipo_medicion_id' => [
                'required',
                'integer',
                /*
                 * Un tipo que esta persona pueda VER -los suyos y las
                 * semillas- **o que esta ficha ya venga usando**.
                 *
                 * Lo segundo no es un agregado: los catálogos son del
                 * usuario, y una ficha compartida la escriben varios. Sin
                 * esa mitad, un cuidador no podría sumar un peso más a la
                 * serie que ya existe -porque el tipo es del dueño de la
                 * ficha, no suyo- y terminaría creando un "Peso" propio: la
                 * misma variable partida en dos, que es exactamente lo que
                 * el catálogo por usuario venía a evitar.
                 *
                 * No expone nada nuevo: son los tipos que esa persona ya
                 * está viendo en el listado de esta misma ficha.
                 *
                 * ⚠️ Los paréntesis no sobran. Sin el `where` anidado, el
                 * `orWhereNull` y el `orWhereIn` se mezclan con la condición
                 * de `id` que `exists` ya agrega, y la regla pasa a aceptar
                 * CUALQUIER tipo que exista. Es el mismo error que ya
                 * apareció en `visiblesPara()` y en la validación de
                 * `medicos.*` de un centro: un OR sin agrupar no falla,
                 * devuelve de más.
                 */
                Rule::exists('tipos_medicion', 'id')->where(
                    fn (Builder $consulta) => $consulta
                        // `exists` va contra la tabla cruda: los soft deletes
                        // no se filtran solos como en Eloquent.
                        ->whereNull('deleted_at')
                        ->where(fn (Builder $ambito) => $ambito
                            ->where(CatalogoVisible::para($this->user()?->getAuthIdentifier()))
                            ->orWhereIn('id', $this->tiposYaUsadosEnLaFicha())
                        ),
                ),
            ],

            'fecha' => ['required', 'date', $this->noPuedeSerFutura()],

            'valor' => ['required', 'numeric'],

            /*
             * El segundo número existe si y solo si el tipo lo declara: una
             * presión sin diastólica no es media presión, es un dato que no
             * se puede leer; y un peso con un segundo número es un fantasma
             * que después nadie sabe qué significaba.
             */
            'valor_secundario' => [
                Rule::requiredIf($tieneSecundario),
                Rule::prohibitedIf(! $tieneSecundario),
                'nullable',
                'numeric',
            ],

            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * La fecha, ya convertida a UTC desde la zona de quien la cargó.
     *
     * Vive acá y no en el controlador para que la conversión sea imposible
     * de olvidar: lo que llega de un `datetime-local` no trae zona, y
     * guardarlo tal cual corre el registro tantas horas como diga el huso.
     */
    public function fechaEnUtc(): ?CarbonImmutable
    {
        $usuario = $this->user();
        $fecha = $this->validated('fecha');

        return $usuario instanceof User && is_string($fecha)
            ? $usuario->aUtc($fecha)
            : null;
    }

    /**
     * El tipo elegido, para saber si pide un segundo valor.
     */
    public function tipo(): ?TipoMedicion
    {
        $id = $this->input('tipo_medicion_id');

        return is_numeric($id) ? TipoMedicion::find((int) $id) : null;
    }

    public function medicionDeLaRuta(): ?Medicion
    {
        $medicion = $this->route('medicion');

        return $medicion instanceof Medicion ? $medicion : null;
    }

    /**
     * El paciente de la ficha.
     *
     * Al cargar viaja como parámetro de ruta; al editar la ruta es
     * {medicion} y el paciente sale del registro. Las dos URLs no comparten
     * forma, así que no alcanza con leer un solo nombre de parámetro.
     */
    public function pacienteId(): ?int
    {
        $paciente = $this->route('paciente');

        if ($paciente instanceof Paciente) {
            return $paciente->id;
        }

        return $this->medicionDeLaRuta()?->paciente_id;
    }

    /**
     * Los tipos que esta ficha ya viene usando.
     *
     * @return list<int>
     */
    private function tiposYaUsadosEnLaFicha(): array
    {
        $pacienteId = $this->pacienteId();

        if ($pacienteId === null) {
            return [];
        }

        // `array_values(array_map(...))` y no `->values()->all()`: la
        // colección de un `pluck` es `array<int, mixed>` para el analizador,
        // y lo que hace falta acá es una lista de enteros.
        return array_values(array_map(
            'intval',
            Medicion::query()
                ->where('paciente_id', $pacienteId)
                ->pluck('tipo_medicion_id')
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
            $techo = CarbonImmutable::now()->addMinutes(self::GRACIA_MINUTOS);

            if ($instante !== null && $instante->greaterThan($techo)) {
                $fallar('No se puede cargar una medición con fecha futura.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipo_medicion_id' => 'tipo de medición',
            'fecha' => 'fecha',
            'valor' => 'valor',
            'valor_secundario' => 'segundo valor',
            'notas' => 'notas',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo_medicion_id.exists' => 'Elegí una variable de tu lista.',
            'valor_secundario.required' => 'Esta variable se carga con dos números.',
            'valor_secundario.prohibited' => 'Esta variable se carga con un solo número.',
        ];
    }
}
