<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Paciente;

/**
 * Lo implementa todo modelo de registro clínico (enfermedades, tratamientos,
 * estudios, turnos...): la Policy del dominio clínico pregunta por acá para
 * decidir a quién autoriza, sin conocer la cadena de relaciones de cada tipo.
 *
 * Casos simples devuelven `$this->paciente`; un adjunto, que cuelga de otro
 * registro y no del paciente directo, sube la cadena en su propia
 * implementación (`adjunto -> estudio -> paciente`).
 */
interface PerteneceAPaciente
{
    public function pacienteAsociado(): Paciente;
}
