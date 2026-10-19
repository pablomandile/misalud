<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Por qué un documento que llegó al buzón no se guardó solo. La pantalla lo
 * dice con estas palabras, para que se entienda qué hay que elegir.
 */
enum MotivoDeRevision: string
{
    /** El asunto no dice Receta, Orden ni Estudio (o dice más de uno). */
    case SinTipo = 'sin_tipo';

    /** La cuenta administra varias fichas y el asunto no nombra a ninguna. */
    case PacienteAmbiguo = 'paciente_ambiguo';

    /**
     * El servidor del remitente no confirmó que el mail sea suyo: puede ser
     * una suplantación, así que no entra a la ficha sin que alguien lo mire.
     */
    case SinVerificar = 'sin_verificar';

    public function explicacion(): string
    {
        return match ($this) {
            self::SinTipo => 'El asunto no decía si es una receta, una orden o un estudio.',
            self::PacienteAmbiguo => 'No supimos de qué paciente es: el asunto no nombraba a ninguno.',
            self::SinVerificar => 'No pudimos confirmar que el mail viniera de verdad de esa dirección. Revisalo antes de guardarlo.',
        };
    }
}
