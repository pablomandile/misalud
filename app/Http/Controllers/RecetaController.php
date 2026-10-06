<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RecetaGuardarRequest;
use App\Http\Requests\RecetaUsoRequest;
use App\Models\Adjunto;
use App\Models\Receta;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La bandeja de recetas: las que llegaron por mail.
 *
 * La pantalla contesta **una sola pregunta: ¿qué receta puedo usar hoy?** De ahí
 * salen las tres decisiones del listado:
 *
 * - **Las disponibles van arriba y separadas** del historial, igual que lo
 *   pendiente en las órdenes de estudio.
 * - **Ordenadas por la que vence primero**, no por la que llegó último: es una
 *   cuenta regresiva, y una receta que vence pasado mañana no puede quedar
 *   debajo de una que vale todo el mes. Mismo razonamiento que la agenda de
 *   turnos, que también ordena al revés que el resto de la app.
 * - **La separación la hace el servidor**, no un `computed()` en la pantalla:
 *   "vencida" depende de la hora, y con el reloj del navegador corrido una
 *   receta vencida aparecería como disponible justo en el mostrador.
 *
 * No hay alta: **las recetas entran solo por importación** (ver `RecetaPolicy`).
 */
class RecetaController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Receta::class);

        /** @var User $usuario */
        $usuario = auth()->user();

        $recetas = $usuario->recetas()
            // Explícito: sin esto es una consulta por receta para sus archivos.
            ->with('adjuntos')
            // En claro, así que el orden puede ser de SQL (ver `ConsultaVigilada`).
            ->orderByDesc('fecha_recepcion')
            ->get();

        [$disponibles, $historial] = $recetas->partition(
            fn (Receta $receta): bool => $receta->estaDisponible(),
        );

        return Inertia::render('recetas/Index', [
            'disponibles' => $disponibles
                ->sortBy(fn (Receta $receta): int => $receta->vence()->getTimestamp())
                ->values()
                ->map(fn (Receta $receta): array => $this->serializar($receta, $usuario))
                ->all(),

            'historial' => $historial
                ->values()
                ->map(fn (Receta $receta): array => $this->serializar($receta, $usuario))
                ->all(),

            'mes' => $this->contadorDelMes($recetas, $usuario),

            // Para el estado vacío: sin casilla, lo que hay que decir es "configurá
            // una", no "no te llegó nada".
            'tieneCasilla' => $usuario->cuentasMail()->exists(),
        ]);
    }

    public function update(RecetaGuardarRequest $peticion, Receta $receta): RedirectResponse
    {
        Gate::authorize('update', $receta);

        $receta->update(['vigencia_dias' => (int) $peticion->validated('vigencia_dias')]);

        return back()->with('exito', 'Se cambió la vigencia.');
    }

    public function uso(RecetaUsoRequest $peticion, Receta $receta): RedirectResponse
    {
        Gate::authorize('update', $receta);

        if ($peticion->boolean('usada')) {
            $receta->marcarUsada();

            return back()->with('exito', 'Marcada como usada.');
        }

        $receta->volverADisponible();

        return back()->with('exito', 'Volvió a quedar sin usar.');
    }

    /**
     * Manda la receta a la papelera.
     *
     * ⚠️ **Y por eso no vuelve a entrar.** Una receta borrada sigue ocupando su
     * `message_id_hash` (el UNIQUE no sabe de soft deletes, y la deduplicación
     * mira la papelera a propósito), así que la importación siguiente la cuenta
     * como repetida. Es justo lo que se quiere al borrar algo que entró y no era
     * una receta -el PDF de una promoción de la farmacia-: si volviera en la
     * próxima corrida, borrarlo no serviría de nada.
     */
    public function destroy(Receta $receta): RedirectResponse
    {
        Gate::authorize('delete', $receta);

        $receta->delete();

        return back()->with('exito', 'Se borró la receta. No se va a volver a importar.');
    }

    /**
     * Lo que llegó en el mes de esta persona.
     *
     * ⚠️ **El mes es el de su zona horaria, no el de UTC.** `fecha_recepcion` es un
     * instante: una receta que llegó el 31 a las 22:00 en Argentina ya es día 1
     * en UTC, y contando en UTC aparecería en el mes siguiente. Se arma el borde
     * del mes en la zona de la cuenta y se compara contra los instantes.
     *
     * Es una **vista sobre lo importado**, no el criterio con el que se importa
     * (ver la ventana de `SincronizadorDeRecetas`). Cuenta las usadas también:
     * la pregunta es cuántas llegaron, no cuántas quedan.
     *
     * @param  iterable<Receta>  $recetas
     * @return array{nombre: string, llegaron: int}
     */
    private function contadorDelMes(iterable $recetas, User $usuario): array
    {
        $inicio = $usuario->ahora()->startOfMonth();
        $fin = $inicio->addMonth();

        $llegaron = 0;

        foreach ($recetas as $receta) {
            if ($receta->fecha_recepcion->greaterThanOrEqualTo($inicio)
                && $receta->fecha_recepcion->lessThan($fin)) {
                $llegaron++;
            }
        }

        return [
            'nombre' => $inicio->translatedFormat('F'),
            'llegaron' => $llegaron,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(Receta $receta, User $usuario): array
    {
        $vence = $usuario->enSuZona($receta->vence()) ?? $receta->vence();

        return [
            'id' => $receta->id,
            'remitente' => $receta->remitente,
            'asunto' => $receta->asunto,
            'llegoVisible' => $usuario->enSuZona($receta->fecha_recepcion)?->format('d/m/Y H:i'),
            'venceVisible' => $vence->format('d/m/Y'),

            /*
             * En días de CALENDARIO de esta persona -medianoche contra
             * medianoche, en su zona-, no en horas divididas por 24: "vence
             * mañana" tiene que querer decir mañana para quien lo lee, aunque
             * falten diecinueve horas.
             */
            'diasParaVencer' => (int) round(
                $usuario->hoy()->diffInDays($vence->startOfDay(), false),
            ),

            'vigencia_dias' => $receta->vigencia_dias,
            'estado' => $receta->estado->value,
            'estadoEtiqueta' => $receta->estado->etiqueta(),
            'estaVencida' => $receta->estaVencida(),
            'usoVisible' => $usuario->enSuZona($receta->fecha_uso)?->format('d/m/Y'),

            'adjuntos' => $receta->adjuntos
                ->sortBy('id')
                ->values()
                ->map(fn (Adjunto $adjunto): array => [
                    'id' => $adjunto->id,
                    'nombre' => $adjunto->nombre_original,
                    'mime' => $adjunto->mime,
                    'tamanio' => $adjunto->tamanio_bytes,
                    'url' => route('adjuntos.show', $adjunto),
                ])
                ->all(),
        ];
    }
}
