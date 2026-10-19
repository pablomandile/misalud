<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Adjunto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Ponerle o cambiarle el nombre y la descripción a un documento ya subido. El
 * archivo no se toca: para eso se borra y se sube otro.
 */
class AdjuntoActualizarRequest extends FormRequest
{
    /**
     * Autorizar ANTES de validar. `update` del adjunto delega en su dueño
     * (`AdjuntoPolicy`): poder editar la ficha, la cobertura o el registro.
     */
    public function authorize(): bool
    {
        $adjunto = $this->route('adjunto');

        return $adjunto instanceof Adjunto && Gate::allows('update', $adjunto);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'titulo' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'titulo' => 'nombre',
            'descripcion' => 'descripción',
        ];
    }
}
