<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RolPaciente;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Invitar a alguien a una ficha.
 *
 * ⚠️ **El rol sale de una lista blanca de dos casos** (`RolPaciente::invitables()`),
 * no de `cases()` menos uno. Con "todos menos Propietario", un rol que se agregue
 * mañana quedaría invitable sin que nadie lo decidiera; con la lista blanca, un
 * rol nuevo nace no invitable. Y `Propietario` no se concede nunca: una ficha
 * tiene un solo dueño.
 */
class InvitarRequest extends FormRequest
{
    public function authorize(): bool
    {
        $paciente = $this->route('paciente');

        return $paciente instanceof Paciente && Gate::allows('compartir', $paciente);
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'rol' => ['required', Rule::in(array_map(
                fn (RolPaciente $rol): string => $rol->value,
                RolPaciente::invitables(),
            ))],
        ];
    }

    /**
     * Quien ya tiene acceso no se invita otra vez.
     *
     * No revela nada: el propietario —el único que llega acá— ya ve en el panel
     * quién tiene acceso. Para cambiar el permiso de alguien está el selector de
     * su fila; reinvitarlo no lo cambiaría (al aceptar, `attach` sobre una fila que
     * ya existe no hace nada).
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

                $paciente = $this->route('paciente');
                $email = (string) $this->input('email');

                $yaEsta = $paciente instanceof Paciente && $paciente->cuidadores
                    ->contains(fn (User $u): bool => mb_strtolower($u->email) === $email);

                if ($yaEsta) {
                    $validador->errors()->add(
                        'email',
                        'Esa persona ya tiene acceso. Para cambiar su permiso, usá el selector de su fila.',
                    );
                }
            },
        ];
    }

    public function rolInvitado(): RolPaciente
    {
        return RolPaciente::from((string) $this->validated('rol'));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['email' => 'dirección de correo', 'rol' => 'permiso'];
    }
}
