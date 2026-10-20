<?php

declare(strict_types=1);

namespace App\Support;

/**
 * El enlace para escribirle a alguien por WhatsApp, o null si no hay número.
 * Lo usan los centros y las farmacias.
 */
final class Whatsapp
{
    /**
     * `wa.me` exige el número internacional sin `+`. El formulario pide el
     * número argentino con código de área, sin el 0 ni el 15 ("11 2345 6789"),
     * que es como la gente lo tiene anotado: si no empieza con 54, se le
     * antepone 549, el prefijo de un celular de Argentina. Uno que ya viene con
     * su código de país (otro país, o "+54 9 …") se respeta tal cual.
     */
    public static function enlace(?string $numero): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $numero) ?? '';

        if ($digitos === '') {
            return null;
        }

        $internacional = str_starts_with((string) $numero, '+') || str_starts_with($digitos, '54')
            ? $digitos
            : '549'.ltrim($digitos, '0');

        return 'https://wa.me/'.$internacional;
    }
}
