<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoOrdenEstudio;
use App\Enums\EstadoTurno;
use App\Enums\TipoAdjunto;
use App\Models\Adjunto;
use App\Models\AplicacionVacuna;
use App\Models\Cobertura;
use App\Models\Medicion;
use App\Models\OrdenEstudio;
use App\Models\Paciente;
use App\Models\Receta;
use App\Models\Tratamiento;
use App\Models\Turno;
use App\Models\User;
use App\Services\SeriesDeMediciones;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel principal: lo que hace falta tener a mano de una ficha, sin entrar a
 * ninguna pantalla.
 *
 * El orden de las tarjetas es el de la urgencia con la que se buscan: la
 * credencial se muestra en un mostrador con alguien esperando, un turno de
 * mañana importa más que una medición de la semana pasada.
 *
 * Cada tarjeta trae **pocas filas y el total**, nunca el listado entero: el
 * panel orienta y lleva a la pantalla de cada cosa, no la reemplaza.
 *
 * Las recetas son la excepción a "todo es de la ficha activa": cuelgan de la
 * casilla del usuario, no de un paciente (ver "Lo que queda afuera del 12.2"
 * en CLAUDE.md), así que esa tarjeta no cambia al elegir otra ficha.
 */
class DashboardController extends Controller
{
    /** Cuántas filas muestra cada tarjeta como máximo. */
    private const FILAS = 3;

    public function __construct(private readonly SeriesDeMediciones $series) {}

    public function index(): Response
    {
        /** @var User $usuario */
        $usuario = auth()->user();

        $pacientes = $this->pacientesOrdenados($usuario);
        $paciente = $this->pacienteActivo($pacientes);

        return Inertia::render('Dashboard', [
            /*
             * Para el selector. Solo nombre e id: con más de una ficha, elegir
             * cuál mirar es lo primero que hace falta, y hasta la Etapa 15 no
             * había ninguna forma de hacerlo.
             */
            'pacientes' => $pacientes
                ->map(fn (Paciente $p): array => ['id' => $p->id, 'nombre' => $p->nombre])
                ->values()
                ->all(),
            'pacienteActivo' => $paciente === null ? null : [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
            ],
            'credenciales' => $paciente === null ? [] : $this->credencialesDe($paciente),
            'proximosTurnos' => $paciente === null ? null : $this->proximosTurnosDe($paciente, $usuario),
            'tratamientosActivos' => $paciente === null ? [] : $this->tratamientosActivosDe($paciente),
            'ordenesPendientes' => $paciente === null ? null : $this->ordenesPendientesDe($paciente),
            'proximasVacunas' => $paciente === null ? [] : $this->proximasVacunasDe($paciente, $usuario),
            'ultimasMediciones' => $paciente === null ? [] : $this->ultimasMedicionesDe($paciente, $usuario),
            'recetas' => $this->recetasSinUsar($usuario),
        ]);
    }

    /**
     * Las fichas que ve esta persona, por nombre —mismo criterio de orden que
     * `PacienteController::index`—. `nombre` está cifrado: no hay `orderBy` en
     * SQL, así que se ordena en PHP (son decenas de filas, como mucho).
     *
     * @return Collection<int, Paciente>
     */
    private function pacientesOrdenados(User $usuario): Collection
    {
        return $usuario->pacientes()
            ->get()
            ->sortBy(fn (Paciente $p): string => $p->nombre)
            ->values();
    }

    /**
     * El paciente activo de la sesión si sigue siendo accesible; si no, el
     * primero por nombre.
     *
     * Las relaciones se cargan **solo para la ficha elegida**: antes se traían
     * las coberturas y los tratamientos de todos los pacientes para mostrar los
     * de uno.
     *
     * @param  Collection<int, Paciente>  $pacientes
     */
    private function pacienteActivo(Collection $pacientes): ?Paciente
    {
        $activoId = session('paciente_activo_id');

        $paciente = ($activoId !== null ? $pacientes->firstWhere('id', $activoId) : null)
            ?? $pacientes->first();

        $paciente?->load([
            'coberturas.adjuntos',
            'tratamientos' => fn ($consulta) => $consulta->where('activo', true)->with('medicamento'),
        ]);

        return $paciente;
    }

    /**
     * La credencial (frente y dorso, lo que haya) de cada cobertura ACTIVA
     * del paciente. Una cobertura dada de baja no se ofrece acá: mostrarla
     * como acceso rápido invitaría a presentar en un mostrador una
     * credencial que ya no sirve.
     *
     * @return list<array{id: int, nombre: string, mime: string, entidad: string, url: string}>
     */
    private function credencialesDe(Paciente $paciente): array
    {
        $credenciales = $paciente->coberturas
            ->where('activa', true)
            ->flatMap(fn (Cobertura $cobertura) => $cobertura->adjuntos
                ->where('tipo', TipoAdjunto::Credencial)
                ->map(fn (Adjunto $adjunto): array => [
                    'id' => $adjunto->id,
                    'nombre' => $adjunto->nombre_original,
                    'mime' => $adjunto->mime,
                    'entidad' => $cobertura->entidad,
                    // Ruta aparte y no `adjuntos.show`: es la única que el
                    // service worker cachea para verse sin señal.
                    'url' => route('credenciales.show', $adjunto),
                ]))
            ->all();

        // array_values() y no solo ->values(): PHPStan no puede garantizar,
        // encadenado, que el resultado de flatMap()->map() ya sea una lista
        // con claves 0..n-1 -aunque en tiempo de ejecución lo sea-.
        return array_values($credenciales);
    }

    /**
     * Los turnos que vienen y siguen en pie, el más cercano primero. "Ahora" lo
     * decide el servidor y no el reloj del navegador, igual que la agenda.
     *
     * @return array{total: int, filas: list<array<string, mixed>>}
     */
    private function proximosTurnosDe(Paciente $paciente, User $usuario): array
    {
        $turnos = $paciente->turnos()
            ->where('estado', EstadoTurno::Programado->value)
            ->where('fecha_hora', '>=', now())
            // En claro: el orden puede ser de SQL.
            ->orderBy('fecha_hora')
            ->with(['medico', 'centro'])
            ->get();

        return [
            'total' => $turnos->count(),
            'filas' => array_values($turnos
                ->take(self::FILAS)
                ->map(fn (Turno $turno): array => [
                    'id' => $turno->id,
                    'fechaVisible' => ($usuario->enSuZona($turno->fecha_hora) ?? $turno->fecha_hora)
                        ->format('d/m/Y \a \l\a\s H:i'),
                    'motivo' => $turno->motivo,
                    'donde' => $turno->medico->nombre ?? $turno->centro->nombre ?? null,
                ])
                ->all()),
        ];
    }

    /**
     * Los tratamientos activos, ya filtrados y con su medicamento cargados
     * en `pacienteActivo()` -acá no se vuelve a consultar la base-.
     *
     * @return list<array{id: int, medicamento: string, dosis: string, frecuencia: string}>
     */
    private function tratamientosActivosDe(Paciente $paciente): array
    {
        return array_values($paciente->tratamientos
            ->sortByDesc(fn (Tratamiento $t): string => $t->inicio->format('Ymd'))
            ->map(fn (Tratamiento $t): array => [
                'id' => $t->id,
                'medicamento' => $t->medicamento->nombre_comercial,
                'dosis' => $t->dosis,
                'frecuencia' => $t->frecuencia,
            ])
            ->all());
    }

    /**
     * Lo que falta hacerse: la pregunta que justifica la tabla de órdenes. La
     * más vieja primero, porque es la que más cerca está de vencerse.
     *
     * @return array{total: int, filas: list<array<string, mixed>>}
     */
    private function ordenesPendientesDe(Paciente $paciente): array
    {
        $ordenes = $paciente->ordenesEstudio()
            ->where('estado', EstadoOrdenEstudio::Pendiente->value)
            ->orderBy('fecha')
            ->get();

        return [
            'total' => $ordenes->count(),
            'filas' => array_values($ordenes
                ->take(self::FILAS)
                ->map(fn (OrdenEstudio $orden): array => [
                    'id' => $orden->id,
                    'estudio' => $orden->estudio_solicitado,
                    'fechaVisible' => $orden->fecha->format('d/m/Y'),
                ])
                ->all()),
        ];
    }

    /**
     * La próxima dosis de cada vacuna que todavía no pasó. Vale la de la ÚLTIMA
     * aplicación de cada vacuna, con el mismo criterio que el recordatorio (ver
     * `AplicacionVacunaObserver`): la de una dosis anterior ya se cumplió.
     *
     * `proxima_dosis` es una fecha de calendario: se compara contra
     * `hoyCalendario()`, no contra `hoy()`.
     *
     * @return list<array{id: int, vacuna: string, fechaVisible: string}>
     */
    private function proximasVacunasDe(Paciente $paciente, User $usuario): array
    {
        $hoy = $usuario->hoyCalendario();

        return array_values($paciente->aplicacionesVacuna()
            ->with('vacuna')
            ->get()
            ->groupBy('vacuna_id')
            ->map(fn (Collection $dosis): AplicacionVacuna => $dosis
                ->sortByDesc(fn (AplicacionVacuna $a): string => $a->fecha->format('Ymd').str_pad((string) $a->id, 10, '0', STR_PAD_LEFT))
                ->first())
            ->filter(fn (AplicacionVacuna $ultima): bool => $ultima->proxima_dosis !== null
                && $ultima->proxima_dosis->greaterThanOrEqualTo($hoy))
            ->sortBy(fn (AplicacionVacuna $ultima): string => $ultima->proxima_dosis?->format('Ymd') ?? '')
            ->take(self::FILAS)
            ->map(fn (AplicacionVacuna $ultima): array => [
                'id' => $ultima->id,
                'vacuna' => $ultima->vacuna->nombre,
                'fechaVisible' => $ultima->proxima_dosis?->format('d/m/Y') ?? '',
            ])
            ->all());
    }

    /**
     * La última toma de cada variable. Se muestra el valor con su unidad y la
     * fecha, nada más: ningún color ni juicio (regla 1).
     *
     * El valor sale por `SeriesDeMediciones::serializar()`, el mismo que usa
     * la pantalla de mediciones: dos formateos distintos del mismo número
     * terminarían mostrando "72,5" en un lado y "72.50" en el otro.
     *
     * @return list<array<string, mixed>>
     */
    private function ultimasMedicionesDe(Paciente $paciente, User $usuario): array
    {
        return array_values($paciente->mediciones()
            ->with('tipo')
            ->orderByDesc('fecha')
            ->get()
            // Sin descifrar nada: agrupa por la FK, que está en claro.
            ->unique('tipo_medicion_id')
            ->take(6)
            ->map(function (Medicion $medicion) use ($usuario): array {
                $serie = $this->series->serializar($medicion, $usuario);

                return [
                    'id' => $medicion->id,
                    'tipo' => $serie['tipoNombre'],
                    'valor' => $serie['valorSecundarioVisible'] !== null
                        ? $serie['valorVisible'].'/'.$serie['valorSecundarioVisible']
                        : $serie['valorVisible'],
                    'unidad' => $serie['unidad'],
                    'fechaVisible' => $serie['fechaVisible'],
                ];
            })
            ->all());
    }

    /**
     * Las recetas sin usar y vigentes de la casilla del usuario: la que vence
     * antes, primero. `null` si no tiene casilla ni recetas, para que la
     * tarjeta no aparezca en una cuenta que nunca usó el módulo.
     *
     * @return array{total: int, filas: list<array<string, mixed>>}|null
     */
    private function recetasSinUsar(User $usuario): ?array
    {
        $recetas = $usuario->recetas()->get();

        if ($recetas->isEmpty() && ! $usuario->cuentasMail()->exists()) {
            return null;
        }

        $disponibles = $recetas
            ->filter(fn (Receta $receta): bool => $receta->estaDisponible())
            ->sortBy(fn (Receta $receta): int => $receta->vence()->getTimestamp())
            ->values();

        return [
            'total' => $disponibles->count(),
            'filas' => array_values($disponibles
                ->take(self::FILAS)
                ->map(fn (Receta $receta): array => [
                    'id' => $receta->id,
                    'asunto' => $receta->asunto,
                    'venceVisible' => ($usuario->enSuZona($receta->vence()) ?? $receta->vence())->format('d/m/Y'),
                ])
                ->all()),
        ];
    }
}
