<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\TamanioTexto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TamanioTextoUpdateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'tamanio_texto' => ['required', Rule::enum(TamanioTexto::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tamanio_texto' => 'tamaño de letra',
        ];
    }

    public function tamanio(): TamanioTexto
    {
        return TamanioTexto::from($this->string('tamanio_texto')->toString());
    }
}
