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

    /**
     * Invitar a alguien o cambiarle el permiso: **solo el propietario**.
     *
     * Un cuidador puede cargar y corregir todo lo clínico, pero decidir quién
     * más ve la historia de una persona no es cargar un dato: es una decisión
     * sobre la ficha entera, y es de quien la creó.
     */
    public function compartir(User $usuario, Paciente $paciente): bool
    {
        return $this->rol($usuario, $paciente) === RolPaciente::Propietario;
    }

    /**
     * Sacarle el acceso a alguien, o irse uno mismo.
     *
     * - El propietario puede sacar a cualquiera **menos a sí mismo**: se quedaría
     *   sin su propia ficha, y con ella sin nadie que pueda compartirla ni darla
     *   de baja.
     * - Cualquier otro solo puede sacarse **a sí mismo** ("dejar de ver esta
     *   ficha"). Sin esto, quien recibió una invitación que no quería quedaría
     *   pegado a ella hasta que el dueño se acuerde de sacarlo.
     */
    public function revocarAcceso(User $usuario, Paciente $paciente, User $otro): bool
    {
        $rolDelOtro = $this->rol($otro, $paciente);

        if ($rolDelOtro === null || $rolDelOtro === RolPaciente::Propietario) {
            return false;
        }

        return $usuario->is($otro) || $this->compartir($usuario, $paciente);
    }

    private function rol(User $usuario, Paciente $paciente): ?RolPaciente
    {
        return $paciente->rolDe($usuario);
    }
}
