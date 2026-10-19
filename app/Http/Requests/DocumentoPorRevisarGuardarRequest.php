<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RolPaciente;
use App\Enums\TipoDocumentoPorMail;
use App\Models\DocumentoPorRevisar;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * "Guardar en…" un documento que quedó por revisar: en qué ficha, como qué y
 * con qué descripción.
 */
class DocumentoPorRevisarGuardarRequest extends FormRequest
{
    /**
     * Autorizar ANTES de validar: si no, a un extraño le contestaría la
     * validación y le confirmaría que el documento existe.
     */
    public function authorize(): bool
    {
        $documento = $this->route('documento');

        return $documento instanceof DocumentoPorRevisar && Gate::allows('update', $documento);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $usuario = $this->user();

        /*
         * Solo una ficha donde esta persona puede cargar datos. Sin esto, un id
         * ajeno puesto a mano metería el documento en la ficha de otro.
         */
        $fichas = $usuario instanceof User
            ? $usuario->pacientes()
                ->wherePivotIn('rol', [RolPaciente::Propietario->value, RolPaciente::Cuidador->value])
                ->pluck('pacientes.id')
                ->all()
            : [];

        return [
            'paciente_id' => ['required', 'integer', Rule::in($fichas)],
            'tipo' => ['required', Rule::enum(TipoDocumentoPorMail::class)],
            'texto' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paciente_id.in' => 'Elegí una de tus fichas.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'paciente_id' => 'paciente',
            'tipo' => 'qué es',
            'texto' => 'descripción',
        ];
    }
}
