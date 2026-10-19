<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lo que el buzón necesita leer de los encabezados crudos de un mail.
 *
 * Funciones puras sobre el texto tal cual llegó: `LectorDeCasilla` -la única
 * pieza sin tests, porque necesitaría un servidor- solo pasa el string. El
 * parser de encabezados del paquete IMAP parte mal los encabezados de varias
 * líneas, y `Authentication-Results` casi siempre ocupa varias.
 */
final class CabecerasDelMail
{
    /**
     * El `Authentication-Results` de MÁS ARRIBA, o null.
     *
     * ⚠️ El de más arriba es el que agregó nuestro servidor de entrada al
     * recibirlo. Uno que aparezca más abajo ya venía en el mail, y ese lo pudo
     * escribir cualquiera: quien quiere suplantar a alguien manda su propio
     * "dmarc=pass". Se lee solo el primero, nunca "alguno que diga pass".
     */
    public static function autenticacion(string $crudas): ?string
    {
        foreach (self::lineas($crudas) as [$nombre, $valor]) {
            if ($nombre === 'authentication-results') {
                return $valor;
            }
        }

        return null;
    }

    /**
     * Si es un mail que mandó una máquina y no una persona: un rebote, una
     * respuesta de vacaciones, una lista de correo.
     *
     * No se le contesta nunca: dos respuestas automáticas contestándose entre
     * sí arman un bucle de mails que no termina solo.
     */
    public static function esAutomatico(string $crudas, string $remitente): bool
    {
        $local = mb_strtolower(explode('@', $remitente)[0]);

        if (in_array($local, ['mailer-daemon', 'postmaster', 'noreply', 'no-reply', 'no_reply', 'donotreply', 'do-not-reply'], true)) {
            return true;
        }

        foreach (self::lineas($crudas) as [$nombre, $valor]) {
            $valor = mb_strtolower(trim($valor));

            $automatico = match ($nombre) {
                // RFC 3834: cualquier valor que no sea "no".
                'auto-submitted' => $valor !== '' && $valor !== 'no',
                'x-autoreply', 'x-autorespond', 'x-auto-response-suppress' => true,
                'precedence' => in_array($valor, ['bulk', 'junk', 'list', 'auto_reply'], true),
                'list-id', 'list-unsubscribe' => true,
                // Un `Return-Path` vacío es la marca de un rebote.
                'return-path' => $valor === '<>',
                default => false,
            };

            if ($automatico) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los encabezados en orden, con las líneas de continuación ya unidas
     * (RFC 5322: una línea que empieza con espacio o tab sigue la anterior).
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function lineas(string $crudas): array
    {
        // Solo los encabezados: hasta la primera línea en blanco.
        $cabecera = preg_split('/\r?\n\r?\n/', $crudas, 2)[0] ?? '';
        $desplegada = preg_replace('/\r?\n[ \t]+/', ' ', $cabecera) ?? $cabecera;

        $lineas = [];

        foreach (preg_split('/\r?\n/', $desplegada) ?: [] as $linea) {
            $dos = strpos($linea, ':');

            if ($dos === false || $dos === 0) {
                continue;
            }

            $lineas[] = [mb_strtolower(trim(substr($linea, 0, $dos))), trim(substr($linea, $dos + 1))];
        }

        return $lineas;
    }
}
