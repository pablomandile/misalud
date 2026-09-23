<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RolPaciente;
use App\Models\Paciente;
use App\Models\User;

/**
 * Toda la autorización pasa por el pivote `paciente_usuario`, nunca por
 * comparar `pacientes.usuario_id`. Es lo que hace que compartir una ficha
 * (Etapa 14) no reescriba nada acá: una fila nueva en el pivote alcanza.
 */
class PacientePolicy
{
    public function viewAny(User $usuario): bool
    {
        return true;
    }

    public function view(User $usuario, Paciente $paciente): bool
    {
        return $this->rol($usuario, $paciente) !== null;
    }

    public function create(User $usuario): bool
    {
        return true;
    }

    public function update(User $usuario, Paciente $paciente): bool
    {
        return $this->rol($usuario, $paciente)?->puedeEditar() ?? false;
    }

    public function delete(User $usuario, Paciente $paciente): bool
    {
        // Dar de baja el paciente entero es solo del propietario.
        return $this->rol($usuario, $paciente) === RolPaciente::Propietario;
    }

    /**
     * Registrar contenido clínico nuevo (estudios, tratamientos, mediciones...).
     * La usa `RegistroClinicoPolicy` para decidir sobre cada tipo de registro.
     */
    public function registrarEventos(User $usuario, Paciente $paciente): bool
    {
        return $this->rol($usuario, $paciente)?->puedeEditar() ?? false;
    }

    private function rol(User $usuario, Paciente $paciente): ?RolPaciente
    {
        return $paciente->rolDe($usuario);
    }
}
