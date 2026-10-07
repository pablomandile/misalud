<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RolPaciente;
use App\Http\Requests\CambiarRolRequest;
use App\Http\Requests\InvitarRequest;
use App\Mail\InvitacionAFicha;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Dar, cambiar y quitar acceso a la ficha de un paciente.
 *
 * Todo es del **propietario** (`PacientePolicy::compartir`), con una excepción:
 * cualquiera se puede ir solo (`revocarAcceso`).
 *
 * ## No hay tabla de invitaciones
 *
 * La invitación **es** una URL firmada, y el pivote `paciente_usuario` es la
 * única fuente de verdad del acceso. Los tres estados ya son representables
 * —pendiente: no hay fila; aceptada: la fila con su rol; revocada: la fila
 * borrada— y una tabla aparte sería una máquina de estados paralela que se puede
 * desincronizar de esa. Es lo que ya funciona en `huella`.
 */
class CompartirController extends Controller
{
    /** Cuánto dura la invitación sin aceptar. */
    private const DIAS_DE_INVITACION = 7;

    /**
     * Invitar por mail.
     *
     * ⚠️ **La firma lleva el mail y el rol adentro.** Reenviar el mail a otra
     * persona no le sirve: al aceptar se compara contra la cuenta con la que
     * entró. Y el rol no se puede subir tocando la URL —`rol=cuidador` en un enlace
     * de lector invalida la firma entera—; igual se revalida al aceptar contra la
     * lista blanca, por si algún día la clave se filtra.
     */
    public function invitar(InvitarRequest $peticion, Paciente $paciente): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $peticion->user();
        $email = (string) $peticion->validated('email');
        $rol = $peticion->rolInvitado();

        // Al final del día del que invita: "vence el 14 de octubre" quiere decir eso.
        $vence = $usuario->hoy()->addDays(self::DIAS_DE_INVITACION)->endOfDay();

        $url = URL::temporarySignedRoute(
            'invitaciones.mostrar',
            $vence,
            ['paciente' => $paciente->id, 'email' => $email, 'rol' => $rol->value],
        );

        Mail::to($email)->send(new InvitacionAFicha(
            quienInvita: $usuario,
            paciente: $paciente,
            rol: $rol,
            url: $url,
            vence: $vence->translatedFormat('j \d\e F'),
        ));

        return back()->with('exito', "Le mandamos la invitación a {$email}.");
    }

    /**
     * Pasar a alguien de lector a cuidador, o al revés.
     *
     * Sin esto, cambiar un permiso obligaría a sacar a la persona, volver a
     * invitarla y que acepte de nuevo: tres pasos para una decisión que es del
     * dueño y de nadie más.
     */
    public function cambiarAcceso(
        CambiarRolRequest $peticion,
        Paciente $paciente,
        User $usuario,
    ): RedirectResponse {
        $actual = $paciente->rolDe($usuario);

        abort_if($actual === null, 404);
        // Al propietario no se le cambia el rol: se quedaría sin su propia ficha.
        abort_if($actual === RolPaciente::Propietario, 403);

        $rol = $peticion->rol();

        // `updateExistingPivot` y no `attach`: la fila ya está.
        $paciente->cuidadores()->updateExistingPivot($usuario->id, ['rol' => $rol->value]);

        return back()->with('exito', "{$usuario->name} ahora tiene permiso de «{$rol->etiqueta()}».");
    }

    /**
     * Quitarle el acceso a alguien, o irse uno mismo.
     *
     * `detach` y no un borrado lógico: el acceso **es** la fila del pivote, y no hay
     * nada que conservar. Volver a invitar crea una nueva.
     */
    public function revocarAcceso(Request $peticion, Paciente $paciente, User $usuario): RedirectResponse
    {
        Gate::authorize('revocarAcceso', [$paciente, $usuario]);

        $paciente->cuidadores()->detach($usuario->id);

        // Quien se fue solo ya no ve la ficha: mandarlo de vuelta ahí sería un 403.
        if ($peticion->user()?->is($usuario)) {
            return redirect()
                ->route('pacientes.index')
                ->with('exito', "Ya no ves la ficha de {$paciente->nombre}.");
        }

        return back()->with('exito', "{$usuario->name} ya no ve la ficha.");
    }
}
