<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de centro médico. `string` en la base, no un ENUM de MySQL: mismo
 * motivo que `TipoCobertura` y `TipoAdjunto`.
 */
enum TipoCentro: string
{
    case Consultorio = 'consultorio';
    case Clinica = 'clinica';
    case Hospital = 'hospital';
    case Sanatorio = 'sanatorio';
    case Laboratorio = 'laboratorio';
    case CentroDiagnostico = 'centro_diagnostico';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Consultorio => 'Consultorio',
            self::Clinica => 'Clínica',
            self::Hospital => 'Hospital',
            self::Sanatorio => 'Sanatorio',
            self::Laboratorio => 'Laboratorio',
            self::CentroDiagnostico => 'Centro de diagnóstico',
        };
    }
}
