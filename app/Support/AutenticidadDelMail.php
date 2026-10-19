<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Si el servidor de entrada confirmó que el mail viene de verdad del dominio
 * que dice el remitente.
 *
 * El `From` de un mail lo escribe quien lo manda: cualquiera puede mandar uno
 * que diga `mama@gmail.com`. Lo que no se puede falsificar es lo que verificó
 * nuestro servidor al recibirlo -SPF (¿el servidor que lo entregó está
 * autorizado por ese dominio?), DKIM (¿la firma es de ese dominio?) y DMARC (la
 * política del dominio aplicada sobre las dos)- y lo deja escrito en el
 * `Authentication-Results` de más arriba (ver `CabecerasDelMail`).
 *
 * Pasa con:
 * - `dmarc=pass`, que ya incluye la alineación con el `From`; o
 * - `dkim=pass` con `header.d` alineado al dominio del remitente; o
 * - `spf=pass` con `smtp.mailfrom` alineado.
 *
 * "Alineado" es el modo relajado de DMARC: el mismo dominio o un subdominio
 * (`mail.ejemplo.com` vale para `ejemplo.com`), nunca uno que apenas termina
 * parecido (`noejemplo.com` no vale). Un `spf=pass` de OTRO dominio no dice nada
 * del remitente: cualquiera tiene SPF válido para su propio servidor.
 *
 * ⚠️ Escrito contra el formato estándar (RFC 8601). Lo que escribe de verdad el
 * servidor de Hostinger se midió con un mail real: ver CLAUDE.md, "Buzón".
 */
final class AutenticidadDelMail
{
    public static function verificada(?string $autenticacion, string $remitente): bool
    {
        if ($autenticacion === null || ! str_contains($remitente, '@')) {
            return false;
        }

        $dominio = self::dominio($remitente);

        foreach (self::resultados($autenticacion) as [$metodo, $resultado, $propiedades]) {
            if ($resultado !== 'pass') {
                continue;
            }

            $verificado = match ($metodo) {
                // Si dice de qué From habla, tiene que ser este.
                'dmarc' => ! isset($propiedades['header.from'])
                    || self::alineados(self::dominio($propiedades['header.from']), $dominio),
                'dkim' => isset($propiedades['header.d'])
                    && self::alineados(self::dominio($propiedades['header.d']), $dominio),
                'spf' => isset($propiedades['smtp.mailfrom'])
                    && self::alineados(self::dominio($propiedades['smtp.mailfrom']), $dominio),
                default => false,
            };

            if ($verificado) {
                return true;
            }
        }

        return false;
    }

    /**
     * `authserv-id; spf=pass smtp.mailfrom=...; dkim=pass header.d=...`
     *
     * @return list<array{0: string, 1: string, 2: array<string, string>}>
     */
    private static function resultados(string $autenticacion): array
    {
        // Los comentarios entre paréntesis no son datos: "dmarc=pass (p=NONE)".
        $limpio = preg_replace('/\([^)]*\)/', ' ', $autenticacion) ?? $autenticacion;
        $partes = explode(';', $limpio);

        // El primero es el identificador del servidor que verificó.
        array_shift($partes);

        $resultados = [];

        foreach ($partes as $parte) {
            if (! preg_match('/^\s*([a-z0-9_-]+)\s*=\s*([a-z]+)(.*)$/is', $parte, $m)) {
                continue;
            }

            preg_match_all('/([a-z0-9_.-]+)\s*=\s*("?)([^\s";]+)\2/i', $m[3], $props, PREG_SET_ORDER);

            $propiedades = [];

            foreach ($props as $prop) {
                $propiedades[mb_strtolower($prop[1])] = mb_strtolower($prop[3]);
            }

            $resultados[] = [mb_strtolower($m[1]), mb_strtolower($m[2]), $propiedades];
        }

        return $resultados;
    }

    private static function dominio(string $valor): string
    {
        $valor = trim(mb_strtolower($valor), " <>\t");
        $arroba = strrpos($valor, '@');

        return $arroba === false ? $valor : substr($valor, $arroba + 1);
    }

    private static function alineados(string $verificado, string $remitente): bool
    {
        if ($verificado === '' || $remitente === '') {
            return false;
        }

        return $verificado === $remitente
            || str_ends_with($remitente, '.'.$verificado)
            || str_ends_with($verificado, '.'.$remitente);
    }
}
