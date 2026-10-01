<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * En qué terminó una prueba de conexión a la casilla.
 *
 * ## ⚠️ Los tres fallos son tres cosas distintas, y por eso no es un booleano
 *
 * Es la decisión que justifica el enum entero. Un "no se pudo conectar" manda
 * a revisar la contraseña cuando el problema puede ser el nombre de la
 * carpeta, y ahí la persona cambia lo único que estaba bien.
 *
 * | Falló…                     | Qué significa de verdad                    |
 * | -------------------------- | ------------------------------------------ |
 * | el socket                  | el host, el puerto o la red del server     |
 * | el login                   | la dirección o la contraseña               |
 * | la carpeta                 | todo anda, pero se va a importar **nada**  |
 *
 * El tercero es el que más vale y el que un booleano no puede decir: una
 * carpeta mal escrita deja la sincronización en verde encontrando cero
 * mensajes, para siempre y sin ningún síntoma. Y es fácil de escribir mal,
 * porque **el separador de carpetas lo elige cada servidor**: la misma carpeta
 * es `INBOX/Recetas` en uno y `INBOX.Recetas` en otro.
 */
enum EstadoDeConexion: string
{
    case Ok = 'ok';
    case SinRed = 'sin_red';
    case CredencialesRechazadas = 'credenciales_rechazadas';
    case CarpetaInexistente = 'carpeta_inexistente';
    case ErrorInesperado = 'error_inesperado';

    public function anduvo(): bool
    {
        return $this === self::Ok;
    }

    /**
     * Qué se le dice a la persona, y qué puede hacer al respecto.
     *
     * Cada mensaje nombra la salida concreta. "Error de conexión" es
     * información para el que ya sabe; acá hace falta que sirva para el que
     * está configurando su casilla por primera vez.
     */
    public function mensaje(): string
    {
        return match ($this) {
            self::Ok => 'La casilla contestó y la carpeta existe: ya se puede importar de acá.',

            self::SinRed => 'No se pudo llegar al servidor. Revisá el nombre y el puerto. '
                .'Si el servidor es correcto, puede que la conexión al puerto esté bloqueada '
                .'desde donde corre la app.',

            self::CredencialesRechazadas => 'El servidor rechazó la dirección o la contraseña. '
                .'Si es una casilla de Gmail, hace falta una contraseña de aplicación: la '
                .'contraseña con la que entrás a Gmail no sirve para IMAP.',

            self::CarpetaInexistente => 'La dirección y la contraseña andan, pero esa carpeta no '
                .'existe en la casilla. Ojo con el separador, que cambia según el servidor.',

            self::ErrorInesperado => 'La conexión falló por un motivo que no esperábamos.',
        };
    }
}
