<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Qué se hizo con un mail del buzón. Queda en `mails_recibidos`.
 *
 * ⚠️ Al sumar un caso hay que ensanchar también el ENUM de MySQL
 * (`mails_recibidos.resultado`): sqlite no valida ENUM y el test pasaría.
 */
enum ResultadoDeMail: string
{
    case Guardado = 'guardado';
    case PorRevisar = 'por_revisar';

    /** Remitente que no es ni una ficha ni una cuenta: se descarta en silencio. */
    case Desconocido = 'desconocido';

    /** Una respuesta automática o un rebote: se descarta sin contestar. */
    case Automatico = 'automatico';

    case SinArchivos = 'sin_archivos';

    /** Pasó el tope de mails por remitente por día. */
    case Excedido = 'excedido';

    case Error = 'error';
}
