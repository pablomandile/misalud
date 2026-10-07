<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RolPaciente;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La pantalla que abre quien recibe una invitación.
 *
 * Vive **fuera** de `auth`: el invitado puede no tener cuenta todavía, y hay que
 * poder decirle qué hacer. Lo que la protege es la firma de la URL, que lleva el
 * mail y el rol adentro.
 *
 * ## ⚠️ No muestra ni un dato clínico
 *
 * Cualquiera con el enlace llega hasta acá. Se muestra el **nombre** del paciente
 * y quién invita —lo justo para reconocer de qué se trata— y nada más: la
 * historia aparece recién después de aceptar con la cuenta correcta.
 *
 * ## La firma se revisa acá y no con el middleware `signed`
 *
 * Con el middleware, un enlace vencido contesta un 403 pelado, y quien lo abre
 * —alguien que capaz nunca usó la app— no sabe qué pasó ni qué hacer. Revisando
 * acá se distinguen los dos casos, y cada uno tiene su respuesta:
 *
 * - **firma incorrecta** (el enlace se cortó al copiarlo, o lo tocaron): no se
 *   muestra nada del paciente, porque nada de lo que trae la URL es confiable;
 * - **firma correcta pero vencida**: los datos sí son auténticos, así que se
 *   puede decir de quién es la ficha y a quién pedirle otra invitación.
 *
 * Aceptar (el POST) sigue exigiendo las dos cosas, sin excepción.
 */
class InvitacionController extends Controller
{
    public function mostrar(Request $peticion, Paciente $paciente): Response
    {
        if (! URL::hasCorrectSignature($peticion)) {
            return $this->pantalla('invalida');
        }

        if (! URL::signatureHasNotExpired($peticion)) {
            return $this->pantalla('vencida', $paciente, $peticion);
        }

        $usuario = $peticion->user();

        // Para que el ingreso o el registro devuelvan acá solos.
        if ($usuario === null) {
            $peticion->session()->put('url.intended', $peticion->fullUrl());
        }

        return $this->pantalla($this->estado($usuario, $paciente, $this->email($peticion)), $paciente, $peticion);
    }

    public function aceptar(Request $peticion, Paciente $paciente): RedirectResponse
    {
        abort_unless(
            URL::hasCorrectSignature($peticion) && URL::signatureHasNotExpired($peticion),
            403,
        );

        $usuario = $peticion->user();
        $estado = $this->estado($usuario, $paciente, $this->email($peticion));

        // Se revalida entero: que la pantalla haya mostrado el botón no prueba nada.
        if ($estado === 'ya_tiene_acceso') {
            return redirect()->route('pacientes.index');
        }

        abort_unless($estado === 'listo' && $usuario instanceof User, 403);

        /*
         * ⚠️ `attach` sobre una fila que no existe, y NUNCA `syncWithoutDetaching`:
         * ese ACTUALIZA el rol si la fila ya está, así que un propietario que abre su
         * propia invitación quedaría degradado a lector de su propia ficha. El
         * unique(paciente_id, usuario_id) no lo frenaría, porque no inserta. El
         * estado de arriba ya descarta a quien tiene acceso; esto es la segunda
         * línea.
         */
        $paciente->cuidadores()->attach($usuario->id, [
            'rol' => $this->rolInvitado($peticion)->value,
        ]);

        return redirect()
            ->route('pacientes.index')
            ->with('exito', "Ya podés ver la ficha de {$paciente->nombre}.");
    }

    /**
     * El rol que concede esta invitación.
     *
     * Viaja en la URL **firmada**: tocarlo invalida la firma. Igual se vuelve a
     * validar contra la lista blanca —nunca `Propietario`—, porque una
     * autorización que depende de un solo candado depende de que ese candado nunca
     * falle, y el candado acá es una clave que algún día se puede filtrar o rotar
     * mal. Sin el parámetro, o con uno que no está en la lista, cae a `Lector`: el
     * que menos puede.
     */
    private function rolInvitado(Request $peticion): RolPaciente
    {
        $rol = RolPaciente::tryFrom((string) $peticion->query('rol'));

        return in_array($rol, RolPaciente::invitables(), true) ? $rol : RolPaciente::Lector;
    }

    private function email(Request $peticion): string
    {
        return mb_strtolower((string) $peticion->query('email'));
    }

    /**
     * En qué situación está quien abrió la invitación.
     *
     * @return 'sin_sesion'|'sin_verificar'|'otra_cuenta'|'ya_tiene_acceso'|'listo'
     */
    private function estado(?User $usuario, Paciente $paciente, string $email): string
    {
        return match (true) {
            $usuario === null => 'sin_sesion',
            // Sin esto, cualquiera podría registrarse declarando el mail de otro y
            // quedarse con la invitación.
            ! $usuario->hasVerifiedEmail() => 'sin_verificar',
            mb_strtolower($usuario->email) !== $email => 'otra_cuenta',
            $paciente->rolDe($usuario) !== null => 'ya_tiene_acceso',
            default => 'listo',
        };
    }

    /**
     * La página, con lo justo según el estado. Con firma incorrecta no va nada del
     * paciente: ningún dato de esa URL es confiable.
     */
    private function pantalla(string $estado, ?Paciente $paciente = null, ?Request $peticion = null): Response
    {
        $rol = $peticion === null ? RolPaciente::Lector : $this->rolInvitado($peticion);

        return Inertia::render('invitaciones/Aceptar', [
            'estado' => $estado,
            'paciente' => $paciente?->nombre,
            'invitadoPor' => $paciente?->usuario?->name,
            'email' => $peticion === null ? null : $this->email($peticion),
            'puedeEditar' => $rol->puedeEditar(),
            // Wayfinder no puede armar una URL firmada: el POST va al string que
            // armó el servidor, con su firma incluida.
            'urlFirmada' => $peticion?->fullUrl(),
        ]);
    }
}
