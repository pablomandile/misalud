<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Adjunto;
use App\Services\EnviadorDeDocumentos;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Mandar documentos a un contacto.
 *
 * ## ⚠️ Cada archivo se autoriza acá, contra SU dueño
 *
 * Es el punto donde este módulo puede filtrar datos de otra persona, y por eso la
 * autorización no se apoya en nada de la pantalla. La lista de documentos que
 * mostró el armado del envío es una comodidad: un id puesto a mano en el
 * formulario —uno de una ficha ajena— tiene que dar 403 acá, y lo da, porque cada
 * archivo pasa por `AdjuntoPolicy` igual que si alguien intentara abrirlo.
 *
 * **Autorizar va antes de validar**, como en el resto del proyecto: si fuera al
 * revés, un id ajeno con otro campo inválido contestaría primero con un error de
 * validación y de paso confirmaría que el archivo existe. Los ids que no existen
 * los deja pasar `authorize()` para que los rechace la validación.
 */
class EnvioGuardarRequest extends FormRequest
{
    /** @var Collection<int, Adjunto>|null */
    private ?Collection $adjuntosCargados = null;

    public function authorize(): bool
    {
        foreach ($this->adjuntosPedidos() as $adjunto) {
            if (! Gate::allows('view', $adjunto)) {
                return false;
            }
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $asunto = $this->input('asunto');

        if (is_string($asunto)) {
            $this->merge(['asunto' => trim($asunto)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * ⚠️ Solo un contacto de la libreta de ESTA persona, nunca una dirección
             * suelta (ver la migración de `contactos`). Uno ajeno falla como "no
             * existe", que es lo correcto: no confirma que exista en otra libreta.
             */
            'contacto_id' => [
                'required',
                'integer',
                Rule::exists('contactos', 'id')->where('usuario_id', $this->user()?->id),
            ],

            'adjuntos' => ['required', 'array', 'min:1', 'max:10'],
            'adjuntos.*' => ['integer', 'distinct', 'exists:adjuntos,id'],

            /*
             * Sin saltos de línea: el asunto va a una cabecera del mail, y un salto
             * de línea en una cabecera es la forma clásica de inyectar otras. El
             * mailer de Symfony ya lo frena, pero acá el error aparece al lado del
             * campo en vez de como un envío fallido.
             */
            'asunto' => ['required', 'string', 'max:150', 'not_regex:/[\r\n]/'],

            'mensaje' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * El tope de tamaño, que solo se puede revisar con los archivos cargados.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validador): void {
                if ($validador->errors()->isNotEmpty()) {
                    return;
                }

                $total = (int) $this->adjuntos()->sum('tamanio_bytes');

                if ($total > EnviadorDeDocumentos::MAXIMO_BYTES) {
                    $validador->errors()->add('adjuntos', sprintf(
                        'Los archivos suman %s MB, y por mail entran hasta %d MB. '
                        .'Sacá alguno y mandalo en otro envío.',
                        number_format($total / (1024 * 1024), 1, ',', ''),
                        intdiv(EnviadorDeDocumentos::MAXIMO_BYTES, 1024 * 1024),
                    ));
                }
            },
        ];
    }

    /**
     * Los archivos validados, en el orden en que se eligieron.
     *
     * @return Collection<int, Adjunto>
     */
    public function adjuntos(): Collection
    {
        // Lista de verdad: con claves sueltas, `array_search` podría devolver una
        // clave string en vez de la posición.
        $orden = array_values(array_map('intval', (array) $this->validated('adjuntos')));

        return $this->adjuntosPedidos()
            ->sortBy(fn (Adjunto $adjunto): int|false => array_search($adjunto->id, $orden, true))
            ->values();
    }

    /**
     * Los que existen de los que se pidieron, sin validar todavía.
     *
     * @return Collection<int, Adjunto>
     */
    private function adjuntosPedidos(): Collection
    {
        if ($this->adjuntosCargados !== null) {
            return $this->adjuntosCargados;
        }

        $ids = collect((array) $this->input('adjuntos', []))
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->adjuntosCargados = Adjunto::query()
            ->with('adjuntable')
            ->whereKey($ids)
            ->get();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'contacto_id' => 'destinatario',
            'adjuntos' => 'documentos',
            'asunto' => 'asunto',
            'mensaje' => 'mensaje',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contacto_id.required' => 'Elegí a quién mandárselo.',
            'contacto_id.exists' => 'Elegí a quién mandárselo de tu libreta.',
            'adjuntos.required' => 'Tildá al menos un documento.',
            'adjuntos.min' => 'Tildá al menos un documento.',
            'adjuntos.max' => 'Entran hasta 10 documentos por envío.',
            'asunto.not_regex' => 'El asunto va en una sola línea.',
        ];
    }
}
