<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CuentaMailGuardarRequest;
use App\Models\CuentaMail;
use App\Services\ProbadorDeCasilla;
use App\Services\SincronizadorDeRecetas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * La casilla de correo de la que se importan las recetas.
 *
 * ## ⚠️ La contraseña NUNCA sale del servidor
 *
 * `serializar()` no la incluye, y no es una omisión que haya que recordar: es
 * la regla que decide la forma de toda la pantalla. De ahí se siguen dos cosas
 * que de otro modo parecerían arbitrarias:
 *
 * - **al editar, el campo vacío significa "dejá la que está"** (lo hace cumplir
 *   `CuentaMailGuardarRequest`, y `update()` saca la clave del array antes de
 *   guardar);
 * - **la prueba de conexión se hace sobre lo GUARDADO**, no sobre lo que hay
 *   escrito en el formulario. Probar el formulario sería más cómodo -arreglás
 *   el tipeo antes de guardar- pero el formulario de edición no tiene la
 *   contraseña, así que probaría una casilla sin credenciales. Y probar lo
 *   guardado es lo que además sirve dentro de seis meses, cuando la pregunta
 *   ya no es "¿lo escribí bien?" sino "¿sigue andando?".
 */
class CuentaMailController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', CuentaMail::class);

        $cuentas = auth()->user()?->cuentasMail()
            /*
             * Por id y no por dirección: `direccion` está cifrada y un
             * `orderBy` sobre ella devolvería las filas en el orden del
             * ciphertext, que es aleatorio. `ConsultaVigilada` lo rechazaría
             * antes, pero el orden correcto igual hay que elegirlo: acá es el
             * de carga, que para una lista de una o dos casillas es el que
             * espera cualquiera.
             */
            ->orderBy('id')
            // Explícito: sin esto es una consulta por casilla para contar sus
            // recetas. "Todo listado con eager loading explícito" vale igual para
            // un contador.
            ->withCount('recetas')
            ->get()
            ->map(fn (CuentaMail $cuenta): array => $this->serializar($cuenta))
            ->all() ?? [];

        return Inertia::render('casilla/Index', [
            'cuentas' => $cuentas,
        ]);
    }

    public function store(CuentaMailGuardarRequest $peticion): RedirectResponse
    {
        Gate::authorize('create', CuentaMail::class);

        /*
         * Por la relación y no con `usuario_id` en el array: es la misma regla
         * que `paciente_id` en el dominio clínico -de esa FK cuelga toda la
         * autorización, así que no es fillable y no se escribe a mano-.
         */
        $peticion->user()->cuentasMail()->create($peticion->validated());

        return back()->with('exito', 'Se guardó la casilla. Probá la conexión para confirmar que anda.');
    }

    public function update(CuentaMailGuardarRequest $peticion, CuentaMail $cuenta): RedirectResponse
    {
        Gate::authorize('update', $cuenta);

        $datos = $peticion->validated();

        /*
         * Vacía significa "no la cambies", no "borrala". Sin esto, editar la
         * carpeta dejaría la casilla sin contraseña y la sincronización
         * empezaría a fallar por un campo que nadie tocó.
         */
        if (($datos['password'] ?? '') === '') {
            unset($datos['password']);
        }

        $cuenta->update($datos);

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(CuentaMail $cuenta): RedirectResponse
    {
        Gate::authorize('delete', $cuenta);

        /*
         * Borrado de verdad, no soft delete: acá vive una contraseña y quien
         * da de baja la casilla espera que se vaya. Ver la migración.
         */
        $cuenta->delete();

        return back()->with('exito', 'Se borró la casilla y su contraseña.');
    }

    /**
     * Abre una sesión IMAP de verdad y cuenta en qué terminó.
     *
     * El resultado va a un toast y **no se guarda**: una prueba es válida en
     * el instante en que se hizo. Guardar "última prueba: anduvo" sería una
     * afirmación que envejece sola —la contraseña de aplicación se revoca, el
     * servidor cambia— y que la pantalla mostraría en verde justo cuando ya
     * dejó de ser cierta.
     */
    public function probar(CuentaMail $cuenta, ProbadorDeCasilla $probador): RedirectResponse
    {
        Gate::authorize('probar', $cuenta);

        $prueba = $probador->probar($cuenta);

        return back()->with(
            $prueba->anduvo() ? 'exito' : 'error',
            $prueba->mensaje(),
        );
    }

    /**
     * Importa ahora, sin esperar a que corra el scheduler.
     *
     * Existe por dos motivos y el segundo es el que la justifica: alguien que
     * acaba de configurar la casilla quiere ver que funciona **ya**, y sin este
     * botón la única forma de comprobar la importación sería esperar hasta una
     * hora o entrar por SSH a correr el comando.
     */
    public function sincronizar(
        CuentaMail $cuenta,
        SincronizadorDeRecetas $sincronizador,
    ): RedirectResponse {
        Gate::authorize('sincronizar', $cuenta);

        try {
            $resumen = $sincronizador->sincronizar($cuenta);
        } catch (Throwable $e) {
            /*
             * El mensaje de la excepción NO va a la pantalla: puede venir del
             * servidor IMAP, en inglés y con partes del comando que lo provocó
             * (ver `ProbadorDeCasilla`). Lo que sirve acá es mandar a la prueba de
             * conexión, que traduce el fallo a algo que se entiende y se puede
             * arreglar.
             */
            Log::error('Falló una sincronización pedida a mano', [
                'cuenta_mail_id' => $cuenta->id,
                'usuario_id' => $cuenta->usuario_id,
                'excepcion' => $e::class,
            ]);

            return back()->with(
                'error',
                'No se pudo importar de esa casilla. Probá la conexión para ver qué está pasando.',
            );
        }

        return back()->with('exito', $resumen->paraPantalla());
    }

    /**
     * ⚠️ Sin `password`. Ver el comentario de la clase.
     *
     * @return array<string, mixed>
     */
    private function serializar(CuentaMail $cuenta): array
    {
        return [
            'id' => $cuenta->id,
            'host' => $cuenta->host,
            'puerto' => $cuenta->puerto,
            'direccion' => $cuenta->direccion,
            'carpeta' => $cuenta->carpeta,
            'filtros' => $cuenta->remitentesAceptados(),

            /*
             * Ahora sí hay algo que la escribe (el paso 12.1 la dejó afuera a
             * propósito). Va en la zona del usuario: es una hora, y una hora en
             * UTC en pantalla no se puede comparar con nada.
             */
            'sincronizadoHasta' => $cuenta->sincronizado_hasta === null
                ? null
                : auth()->user()?->enSuZona($cuenta->sincronizado_hasta)?->format('d/m/Y H:i'),

            'recetasImportadas' => (int) ($cuenta->recetas_count ?? 0),
        ];
    }
}
