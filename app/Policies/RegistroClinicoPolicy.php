<?php

declare(strict_types=1);

namespace App\Policies;

use App\Contracts\PerteneceAPaciente;
use App\Enums\RolPaciente;
use App\Models\Paciente;
use App\Models\User;

/**
 * **Una sola Policy para todo el dominio clínico.**
 *
 * Estudios, tratamientos, mediciones, adjuntos, órdenes: las reglas son
 * idénticas en todos —las decide el rol en `paciente_usuario`— y lo único que
 * cambia es cómo se llega al paciente, que lo resuelve cada modelo con
 * `PerteneceAPaciente`.
 *
 * Una Policy por modelo sería la misma lógica copiada quince veces, y la
 * decimosexta —la que alguien escriba apurado en seis meses— sería la que
 * filtre. Acá hay un solo lugar donde equivocarse, y está cubierto por tests.
 *
 * Se registra con `#[UsePolicy(RegistroClinicoPolicy::class)]` en cada modelo.
 */
class RegistroClinicoPolicy
{
    public function view(User $usuario, PerteneceAPaciente $registro): bool
    {
        // Leer alcanza con tener CUALQUIER rol, incluido `Lector`: es lo que se
        // concede por el enlace de compartir de la Etapa 14.
        return $this->rol($usuario, $registro) !== null;
    }

    public function update(User $usuario, PerteneceAPaciente $registro): bool
    {
        return $this->rol($usuario, $registro)?->puedeEditar() ?? false;
    }

    public function delete(User $usuario, PerteneceAPaciente $registro): bool
    {
        /*
         * Borrar un registro clínico NO está reservado al propietario, a
         * diferencia de borrar al paciente entero: un cuidador que carga cosas
         * tiene que poder corregir lo que cargó mal. Lo que no puede es dar de
         * baja la ficha completa, que es la decisión irreversible.
         */
        return $this->rol($usuario, $registro)?->puedeEditar() ?? false;
    }

    public function restore(User $usuario, PerteneceAPaciente $registro): bool
    {
        return $this->update($usuario, $registro);
    }

    public function forceDelete(User $usuario, PerteneceAPaciente $registro): bool
    {
        // El borrado definitivo sí es del propietario: no tiene vuelta atrás.
        return $this->rol($usuario, $registro) === RolPaciente::Propietario;
    }

    /**
     * Crear contenido clínico nuevo dentro de una ficha.
     *
     * No recibe el registro -todavía no existe- sino el paciente donde va.
     */
    public function crearEn(User $usuario, Paciente $paciente): bool
    {
        return $paciente->rolDe($usuario)?->puedeEditar() ?? false;
    }

    /**
     * El rol del usuario sobre el paciente dueño del registro.
     *
     * **Un paciente nulo devuelve null, y null es "no".** Es la línea que
     * separa un registro huérfano inaccesible de uno abierto a cualquiera:
     * si la cadena hacia el paciente se rompe, se niega el acceso, no se
     * asume que no hay nada que proteger.
     */
    private function rol(User $usuario, PerteneceAPaciente $registro): ?RolPaciente
    {
        return $registro->pacienteDelRegistro()?->rolDe($usuario);
    }
}
