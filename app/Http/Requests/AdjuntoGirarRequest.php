<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Adjunto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Girar una foto ya subida, de a 90°.
 */
class AdjuntoGirarRequest extends FormRequest
{
    /**
     * Autorizar ANTES de validar. Girar es editar el archivo, así que pide
     * `update` del adjunto, que delega en su dueño (`AdjuntoPolicy`).
     */
    public function authorize(): bool
    {
        $adjunto = $this->route('adjunto');

        return $adjunto instanceof Adjunto && Gate::allows('update', $adjunto);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'sentido' => ['required', Rule::in(['izquierda', 'derecha'])],
        ];
    }

    /**
     * 90 a la derecha (horario), -90 a la izquierda.
     */
    public function grados(): int
    {
        return $this->validated('sentido') === 'derecha' ? 90 : -90;
    }
}
