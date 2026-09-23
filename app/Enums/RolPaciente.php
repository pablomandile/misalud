<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rol de un usuario sobre un paciente, en el pivote `paciente_usuario`.
 *
 * `Propietario` es quien dio de alta al paciente (o el usuario mismo, si es su
 * propia ficha). `Cuidador` puede cargar y editar. `Lector` solo mira: es lo
 * que se ofrece por el enlace de compartir de la Etapa 14.
 */
enum RolPaciente: string
{
    case Propietario = 'propietario';
    case Cuidador = 'cuidador';
    case Lector = 'lector';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Propietario => 'Propietario',
            self::Cuidador => 'Cuidador',
            self::Lector => 'Solo lectura',
        };
    }

    public function puedeEditar(): bool
    {
        return match ($this) {
            self::Propietario, self::Cuidador => true,
            self::Lector => false,
        };
    }

    /**
     * Los roles que el propietario puede conceder al compartir la ficha
     * (Etapa 14).
     *
     * **Lista blanca**, no `cases()` menos uno: si el enum suma un rol nuevo
     * el día de mañana, nace prohibido y hay que habilitarlo a mano. Con
     * `cases()` menos uno pasaría lo contrario — se habilitaría solo.
     *
     * @return list<self>
     */
    public static function invitables(): array
    {
        return [self::Lector, self::Cuidador];
    }
}
