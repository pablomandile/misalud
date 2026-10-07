<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RolPaciente;
use App\Models\Paciente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Cambiarle el permiso a alguien que ya tiene acceso.
 *
 * Misma lista blanca que la invitación: se puede pasar de lector a cuidador y
 * al revés, **nunca a propietario**.
 */
class CambiarRolRequest extends FormRequest
{
    public function authorize(): bool
    {
        $paciente = $this->route('paciente');

        return $paciente instanceof Paciente && Gate::allows('compartir', $paciente);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'rol' => ['required', Rule::in(array_map(
                fn (RolPaciente $rol): string => $rol->value,
                RolPaciente::invitables(),
            ))],
        ];
    }

    public function rol(): RolPaciente
    {
        return RolPaciente::from((string) $this->validated('rol'));
    }
}
