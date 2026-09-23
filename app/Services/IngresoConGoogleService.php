<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\CuentaDeGoogle;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Alta y vínculo de cuentas que entran con Google.
 *
 * La regla de fondo: **el email manda**. Google ya lo verificó, así que si
 * coincide con una cuenta que existe es la misma persona y se le vincula el
 * `google_id` en vez de crear otra. Dos cuentas con el mismo email dejarían a
 * alguien con dos juegos de pacientes separados -la ficha de su madre en una y
 * la propia en la otra-, cada uno invisible desde el otro, y sin ninguna forma
 * de juntarlos después.
 */
class IngresoConGoogleService
{
    /**
     * ¿Está configurado el ingreso con Google?
     *
     * Se pregunta antes de mostrar el botón y también en las rutas: sin
     * credenciales, la app tiene que funcionar como si la opción no existiera,
     * no tirar un 500.
     */
    public static function configurado(): bool
    {
        /*
         * `Config::get` y no `Config::string`: sin las variables en el .env el
         * valor es null, y `Config::string` lo toma como un tipo inválido y
         * tira excepción en vez de devolver el default. Es decir, el helper
         * "seguro" rompería exactamente en el caso que esto viene a cubrir.
         */
        return filled(Config::get('services.google.client_id'))
            && filled(Config::get('services.google.client_secret'));
    }

    /**
     * El usuario de MiSalud detrás de una cuenta de Google, creándolo si hace
     * falta.
     */
    public function resolver(CuentaDeGoogle $cuenta): User
    {
        if (blank($cuenta->id) || blank($cuenta->email)) {
            // Sin email no hay forma de vincular la cuenta ni de avisarle nada.
            throw new RuntimeException('Google no devolvió el email de la cuenta.');
        }

        if (! $cuenta->emailVerificado) {
            throw new RuntimeException('La cuenta de Google no tiene el email verificado.');
        }

        return DB::transaction(function () use ($cuenta): User {
            $porGoogle = User::where('google_id', $cuenta->id)->first();

            if ($porGoogle !== null) {
                /*
                 * Se reconoce por el `sub` de Google y no por el email: el
                 * email de una cuenta de Google se puede cambiar, el
                 * identificador no. Si cambió, se actualiza el nuestro.
                 */
                $porGoogle->email = $cuenta->email;
                $porGoogle->save();

                return $porGoogle;
            }

            $porEmail = User::where('email', $cuenta->email)->first();

            if ($porEmail !== null) {
                $porEmail->google_id = $cuenta->id;
                $porEmail->save();

                // Si se había registrado por email y todavía no lo había
                // confirmado, Google acaba de confirmarlo por él.
                if (! $porEmail->hasVerifiedEmail()) {
                    $porEmail->markEmailAsVerified();
                }

                return $porEmail;
            }

            return $this->crear($cuenta);
        });
    }

    /**
     * Cuenta nueva. Queda **sin contraseña**: nunca eligió una, y guardarle una
     * al azar la haría figurar como que puede entrar con email y clave.
     */
    private function crear(CuentaDeGoogle $cuenta): User
    {
        $usuario = new User;

        /*
         * `forceFill` y no asignación masiva: `google_id`, `password` y
         * `email_verified_at` no son fillable a propósito, y acá se escriben
         * desde el sistema y no desde un formulario.
         */
        $usuario->forceFill([
            'name' => $cuenta->nombre ?: (string) str($cuenta->email)->before('@'),
            'email' => $cuenta->email,
            'google_id' => $cuenta->id,
            'password' => null,
            // Google ya verificó el email: pedirle que confirme el mismo email
            // sería hacerlo esperar un mail para nada.
            'email_verified_at' => $usuario->freshTimestamp(),
        ]);

        // El tamaño de letra queda en el default del modelo (Grande), que es el
        // que corresponde a quien todavía no eligió.
        $usuario->save();

        return $usuario;
    }
}
