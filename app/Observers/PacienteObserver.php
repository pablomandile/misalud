<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\RolPaciente;
use App\Models\Paciente;

/**
 * Crea la fila del propietario en el pivote al dar de alta un paciente.
 *
 * El alta pasa siempre por un `User`, así que el pivote nunca queda sin
 * ninguna fila -y por lo tanto sin ningún `rolDe()` posible- para su propio
 * creador.
 */
class PacienteObserver
{
    public function created(Paciente $paciente): void
    {
        $paciente->cuidadores()->attach($paciente->usuario_id, [
            'rol' => RolPaciente::Propietario->value,
        ]);
    }
}
