<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RolPaciente;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * La ficha de la persona que usa la cuenta: la que comparte el correo con el
 * login. Es a la que llevan directo Credencial, Variables y Medicamentos del
 * menú.
 *
 * Si ninguna ficha tiene ese correo, la primera de la que la cuenta es
 * propietaria (decisión del usuario): quien administra solo la ficha de su
 * mamá no tiene "una propia", y el menú tiene que llevarlo a algún lado.
 */
final class PacientePrincipal
{
    public static function de(User $usuario): ?Paciente
    {
        /** @var Collection<int, Paciente> $fichas */
        $fichas = $usuario->pacientes()
            ->get()
            ->sortBy(fn (Paciente $paciente): string => mb_strtolower((string) $paciente->nombre))
            ->values();

        $hash = Paciente::hashCiego((string) $usuario->email);
        $propia = $fichas->first(fn (Paciente $paciente): bool => $paciente->email_hash === $hash);

        return $propia ?? $fichas->first(
            fn (Paciente $paciente): bool => $paciente->getRelationValue('pivot')?->getAttribute('rol') === RolPaciente::Propietario->value,
        );
    }

    /**
     * Las fichas entre las que se puede cambiar, para el selector.
     *
     * @return list<array{id: int, nombre: string}>
     */
    public static function opciones(User $usuario): array
    {
        return array_values($usuario->pacientes()
            ->get()
            ->sortBy(fn (Paciente $paciente): string => mb_strtolower((string) $paciente->nombre))
            ->map(fn (Paciente $paciente): array => ['id' => $paciente->id, 'nombre' => (string) $paciente->nombre])
            ->all());
    }
}
