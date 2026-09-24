<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SeveridadAlergia;
use App\Models\Alergia;
use App\Models\Paciente;
use App\Rules\IndiceCiegoUnico;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AlergiaGuardarRequest extends FormRequest
{
    public function authorize(): bool
    {
        $alergia = $this->alergiaDeLaRuta();

        if ($alergia !== null) {
            return Gate::allows('update', $alergia);
        }

        $paciente = $this->route('paciente');

        return $paciente instanceof Paciente
            && Gate::allows('crearEn', [Alergia::class, $paciente]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'sustancia' => [
                'required',
                'string',
                'max:255',
                /*
                 * Una sola vez cada sustancia por paciente: dos "Penicilina"
                 * con severidades distintas no dejarían saber cuál vale. Va
                 * por el índice ciego porque `sustancia` está cifrada —un
                 * `Rule::unique` no detectaría nada y lo cortaría la base con
                 * un 500 en vez de un error de campo—.
                 */
                new IndiceCiegoUnico(
                    Alergia::class,
                    'sustancia',
                    ['paciente_id' => $this->pacienteId()],
                    $this->alergiaDeLaRuta()?->id,
                ),
            ],
            'reaccion' => ['nullable', 'string', 'max:255'],
            'severidad' => ['required', Rule::enum(SeveridadAlergia::class)],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function alergiaDeLaRuta(): ?Alergia
    {
        $alergia = $this->route('alergia');

        return $alergia instanceof Alergia ? $alergia : null;
    }

    private function pacienteId(): ?int
    {
        $paciente = $this->route('paciente');

        if ($paciente instanceof Paciente) {
            return $paciente->id;
        }

        return $this->alergiaDeLaRuta()?->paciente_id;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sustancia' => 'sustancia',
            'reaccion' => 'reacción',
            'severidad' => 'severidad',
            'notas' => 'notas',
        ];
    }
}
