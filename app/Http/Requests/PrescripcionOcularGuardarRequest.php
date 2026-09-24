<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Ojo;
use App\Enums\TipoPrescripcionOcular;
use App\Models\GraduacionOcular;
use App\Models\Paciente;
use App\Models\PrescripcionOcular;
use App\Models\User;
use App\Rules\FechaNoFutura;
use App\Rules\PasoDeDioptria;
use App\Support\CatalogoVisible;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * La validación clínica de una receta de anteojos.
 *
 * El criterio que ordena todo lo de abajo, y que conviene tener presente
 * antes de agregarle una regla más:
 *
 * > **Se rechaza lo que no puede existir, nunca lo que es poco común.**
 *
 * Un `-1,30` de esfera no es una graduación rara: no se fabrica, así que es
 * un tipeo, y atajarlo evita unos anteojos mal hechos. Una receta "para
 * lejos" que además trae una adición sí es rara, pero **existe** —es el
 * papel que esa persona tiene en la mano—, y rechazarla la dejaría sin poder
 * cargar lo que dice su receta. Eso sería opinar sobre el contenido, que es
 * justo lo que la regla 1 del proyecto no hace.
 */
class PrescripcionOcularGuardarRequest extends FormRequest
{
    /**
     * Autorizar ANTES de validar: si no, a alguien sin permiso le contesta
     * primero la validación y de paso le confirma que la ficha existe.
     */
    public function authorize(): bool
    {
        $prescripcion = $this->prescripcionDeLaRuta();

        if ($prescripcion !== null) {
            return Gate::allows('update', $prescripcion);
        }

        $paciente = $this->route('paciente');

        return $paciente instanceof Paciente
            && Gate::allows('crearEn', [PrescripcionOcular::class, $paciente]);
    }

    /**
     * ⚠️ **El trait `NormalizaDecimales` no sirve acá, y no es un descuido.**
     *
     * Ese trait hace `merge(['valor' => ...])`, que para un campo anidado
     * crearía la clave literal `'od.esfera'` —con el punto adentro del
     * nombre— en vez de escribir dentro del array `od`. El valor original
     * quedaría intacto, la coma sin convertir, y `numeric` rechazaría un
     * "-1,25" perfectamente válido sin que nada explicara por qué. Por eso
     * acá se reconstruye entero el array de cada ojo.
     *
     * La coma no es un capricho: un teclado numérico de celular en español
     * ofrece la coma, y una receta argentina se lee "-1,25".
     */
    protected function prepareForValidation(): void
    {
        $normalizado = [];

        foreach (Ojo::cases() as $ojo) {
            $datos = $this->input($ojo->value);

            if (! is_array($datos)) {
                continue;
            }

            foreach (GraduacionOcular::CAMPOS_NUMERICOS as $campo) {
                $valor = $datos[$campo] ?? null;

                if (is_string($valor)) {
                    $limpio = str_replace(',', '.', trim($valor));
                    // Un campo con solo espacios es un campo vacío, no un
                    // texto que `numeric` tenga que rechazar.
                    $datos[$campo] = $limpio === '' ? null : $limpio;
                }
            }

            $normalizado[$ojo->value] = $datos;
        }

        $dpTotal = $this->input('dp_total');

        if (is_string($dpTotal)) {
            $limpio = str_replace(',', '.', trim($dpTotal));
            $normalizado['dp_total'] = $limpio === '' ? null : $limpio;
        }

        if ($normalizado !== []) {
            $this->merge($normalizado);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $usuario = $this->user();
        $usuarioId = $usuario?->getAuthIdentifier();

        $reglas = [
            'tipo' => ['required', Rule::enum(TipoPrescripcionOcular::class)],

            // Una receta es algo que YA le dieron a alguien: no se puede
            // cargar una de mañana. Fecha de calendario, por `FechaNoFutura`.
            'fecha' => array_filter([
                'required',
                'date',
                $usuario instanceof User ? new FechaNoFutura($usuario) : null,
            ]),

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
             * La distancia pupilar de los dos ojos juntos, en milímetros. El
             * rango cubre de un chico a un adulto grande, y su trabajo es
             * atajar un tipeo —un "630" por un "63"—, no juzgar una cara.
             *
             * ⚠️ **No se compara contra la suma de las dos monoculares**, a
             * propósito: se miden por separado y el redondeo a medio
             * milímetro las hace diferir seguido. Cruzarlas rechazaría
             * recetas correctas.
             */
            'dp_total' => ['nullable', 'numeric', 'decimal:0,2', 'between:40,85'],

            'notas' => ['nullable', 'string', 'max:2000'],
        ];

        foreach (Ojo::cases() as $ojo) {
            $reglas += $this->reglasDelOjo($ojo);
        }

        return $reglas;
    }

    /**
     * Las ocho reglas de un ojo.
     *
     * @return array<string, array<int, mixed>>
     */
    private function reglasDelOjo(Ojo $ojo): array
    {
        $o = $ojo->value;
        $tieneCilindro = $this->tieneCilindro($ojo);

        return [
            /*
             * Esfera: la potencia principal, con signo —el signo es lo que
             * separa una miopía de una hipermetropía, y lo primero que se
             * pierde al copiar a mano—.
             *
             * El plan ponía ±20. Se amplió a ±25 porque una miopía alta
             * llega ahí y el rango está para atrapar un tipeo (un "125" por
             * un "1,25"), no para decidir hasta dónde puede ver alguien.
             */
            "{$o}.esfera" => [
                'nullable', 'numeric', 'decimal:0,2', 'between:-25,25', new PasoDeDioptria,
            ],

            /*
             * Cilindro: el astigmatismo. El plan ponía ±6; un queratocono
             * pasa de ahí sin ser un error de carga, así que va ±12 —mismo
             * criterio que la esfera—.
             *
             * **Se guarda con el signo que trae el papel.** Las ópticas
             * escriben en cilindro negativo o positivo según la costumbre y
             * las dos formas son equivalentes; normalizar acá haría que lo
             * de la pantalla no coincidiera con lo que la persona tiene en
             * la mano. La otra forma la muestra el botón de transponer
             * (paso 10.3), sin tocar lo guardado.
             */
            "{$o}.cilindro" => [
                'nullable', 'numeric', 'decimal:0,2', 'between:-12,12', new PasoDeDioptria,
            ],

            /*
             * ⚠️ El eje es **obligatorio o prohibido, nunca opcional** —la
             * misma forma que el segundo valor de una medición—, y acá el
             * motivo es más duro que allá:
             *
             * - un cilindro sin eje **no se puede fabricar**: el eje es la
             *   orientación en la que va esa corrección, y sin ella la lente
             *   no existe;
             * - un eje sin cilindro es un número que no corrige nada y que
             *   dentro de un año nadie va a saber qué significaba.
             *
             * Un cilindro en `0` cuenta como "sin cilindro": un "0,00 x 180"
             * es una costumbre de escritura, no una corrección.
             *
             * Va de 0 a 180 y no a 360 porque un eje es un **meridiano**:
             * 20° y 200° son la misma línea. Y entero, que es como se escribe.
             */
            "{$o}.eje" => [
                Rule::requiredIf($tieneCilindro),
                Rule::prohibitedIf(! $tieneCilindro),
                'nullable', 'integer', 'between:0,180',
            ],

            /*
             * Adición: lo que se suma para ver de cerca. **Siempre
             * positiva** —es una suma—, así que un valor negativo es un
             * error de signo y no una adición rara.
             *
             * No se cruza con `tipo`: una receta "para lejos" que además
             * trae una adición existe, y rechazarla sería opinar sobre el
             * papel de otro (ver el comentario de la clase).
             */
            "{$o}.adicion" => [
                'nullable', 'numeric', 'decimal:0,2', 'between:0.25,4', new PasoDeDioptria,
            ],

            "{$o}.dp_monocular" => [
                'nullable', 'numeric', 'decimal:0,2', 'between:20,45',
            ],

            /*
             * Prisma y base son **el otro par que va junto o no va**: un
             * prisma sin base no dice hacia dónde desvía, y una base sin
             * prisma no dice cuánto. Ninguno de los dos se puede fabricar
             * solo.
             *
             * Al prisma **no** se le aplica `PasoDeDioptria`: una dioptría
             * prismática no es la misma dioptría y no va de 0,25 en 0,25.
             * Y la base queda en texto libre porque cada óptica la escribe
             * distinto —"base externa", "BE", "base 180"—, y encajarla en
             * una lista cerrada rechazaría media receta.
             */
            "{$o}.prisma" => [
                Rule::requiredIf($this->tieneAlgo($ojo, 'base')),
                'nullable', 'numeric', 'decimal:0,2', 'between:0.25,20',
            ],

            "{$o}.base" => [
                Rule::requiredIf($this->tieneAlgo($ojo, 'prisma')),
                'nullable', 'string', 'max:30',
            ],

            // "20/20", "10/10", "0,8", "cuenta dedos": no hay forma honesta
            // de validar esto más allá del largo.
            "{$o}.agudeza_visual" => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * Lo que va a la fila de un ojo, con el ojo ya puesto.
     *
     * @return array<string, mixed>
     */
    public function datosDelOjo(Ojo $ojo): array
    {
        $datos = $this->validated($ojo->value);

        return ['ojo' => $ojo] + (is_array($datos) ? $datos : []);
    }

    public function prescripcionDeLaRuta(): ?PrescripcionOcular
    {
        $prescripcion = $this->route('prescripcion');

        return $prescripcion instanceof PrescripcionOcular ? $prescripcion : null;
    }

    /**
     * ¿Este ojo trae un cilindro que corrija algo?
     *
     * Un `0` no cuenta: es "sin astigmatismo" escrito de más.
     */
    private function tieneCilindro(Ojo $ojo): bool
    {
        $cilindro = $this->input("{$ojo->value}.cilindro");

        return is_numeric($cilindro) && (float) $cilindro !== 0.0;
    }

    private function tieneAlgo(Ojo $ojo, string $campo): bool
    {
        $valor = $this->input("{$ojo->value}.{$campo}");

        return $valor !== null && $valor !== '';
    }

    private function pacienteId(): ?int
    {
        $paciente = $this->route('paciente');

        if ($paciente instanceof Paciente) {
            return $paciente->id;
        }

        return $this->prescripcionDeLaRuta()?->paciente_id;
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
            PrescripcionOcular::query()
                ->where('paciente_id', $pacienteId)
                ->whereNotNull($columna)
                ->pluck($columna)
                ->unique()
                ->all(),
        ));
    }

    /**
     * Cada campo con el ojo adentro del nombre.
     *
     * Sin esto, un error de la esfera del ojo izquierdo diría "el campo
     * oi.esfera", que no es algo que nadie pueda leer.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $nombres = [
            'esfera' => 'esfera',
            'cilindro' => 'cilindro',
            'eje' => 'eje',
            'adicion' => 'adición',
            'dp_monocular' => 'distancia pupilar',
            'prisma' => 'prisma',
            'base' => 'base',
            'agudeza_visual' => 'agudeza visual',
        ];

        $atributos = [
            'tipo' => 'tipo de receta',
            'fecha' => 'fecha',
            'medico_id' => 'médico',
            'centro_id' => 'centro',
            'dp_total' => 'distancia pupilar total',
            'notas' => 'notas',
        ];

        foreach (Ojo::cases() as $ojo) {
            foreach ($nombres as $campo => $nombre) {
                $atributos["{$ojo->value}.{$campo}"] = "{$nombre} del {$ojo->etiqueta()}";
            }
        }

        return $atributos;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $mensajes = [
            'medico_id.exists' => 'Elegí un médico de tu agenda.',
            'centro_id.exists' => 'Elegí un centro de tu catálogo.',
        ];

        foreach (Ojo::cases() as $ojo) {
            $o = $ojo->value;

            $mensajes["{$o}.eje.required"] = 'Falta el eje: un cilindro sin eje no se puede fabricar.';
            $mensajes["{$o}.eje.prohibited"] = 'El eje solo tiene sentido junto a un cilindro.';
            $mensajes["{$o}.prisma.required"] = 'Falta el prisma que corresponde a esa base.';
            $mensajes["{$o}.base.required"] = 'Falta la base: un prisma sin base no se puede fabricar.';
        }

        return $mensajes;
    }
}
