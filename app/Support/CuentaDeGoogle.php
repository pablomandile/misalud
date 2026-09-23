<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as UsuarioDeSocialite;

/**
 * Lo único que MiSalud necesita saber de una cuenta de Google.
 *
 * Existe para que el servicio que da de alta usuarios no dependa de Socialite:
 * la librería devuelve un objeto con veinte campos y su propia jerarquía de
 * clases, y el dato de si el email está verificado ni siquiera está en su
 * interfaz -vive en el payload crudo-. Traducir acá deja esa rareza en un solo
 * lugar, y permite probar el alta sin hablar con Google.
 */
final readonly class CuentaDeGoogle
{
    public function __construct(
        public string $id,
        public string $email,
        public ?string $nombre,
        public bool $emailVerificado,
    ) {}

    /**
     * Traduce lo que devolvió Socialite.
     */
    public static function desdeSocialite(UsuarioDeSocialite $usuario): self
    {
        return new self(
            id: (string) $usuario->getId(),
            email: (string) $usuario->getEmail(),
            nombre: $usuario->getName(),
            emailVerificado: self::emailVerificado($usuario),
        );
    }

    /**
     * Google manda el flag en el payload crudo, que solo expone `AbstractUser`.
     *
     * Si no viene, el email NO se da por verificado. Es la decisión que sostiene
     * todo lo demás: con un email sin verificar, cualquiera podría crear una
     * cuenta de Google declarando la dirección de otra persona y quedarse con
     * su historia clínica entera, que es el peor resultado posible acá.
     */
    private static function emailVerificado(UsuarioDeSocialite $usuario): bool
    {
        if (! $usuario instanceof AbstractUser) {
            return false;
        }

        $crudo = $usuario->getRaw();

        // Las dos claves aparecen según qué endpoint de Google haya respondido.
        foreach (['verified_email', 'email_verified'] as $clave) {
            if (array_key_exists($clave, $crudo)) {
                return filter_var($crudo[$clave], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return false;
    }
}
