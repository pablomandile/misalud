<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AplicacionVacunaGuardarRequest;
use App\Models\Adjunto;
use App\Models\AplicacionVacuna;
use App\Models\Centro;
use App\Models\Paciente;
use App\Models\Vacuna;
use App\Support\CatalogoVisible;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El carnet de vacunación de un paciente.
 *
 * Agrupado por vacuna y no como una lista cronológica: la pregunta que se le
 * hace a un carnet es "¿cuántas dosis de la hepatitis B tengo?", y una
 * antigripal entre medio no ayuda a contestarla.
 */
class AplicacionVacunaController extends Controller
{
    public function index(Paciente $paciente): Response
    {
        Gate::authorize('view', $paciente);

        $usuario = auth()->user();

        $aplicaciones = $paciente->aplicacionesVacuna()
            // Explícito: sin esto es una consulta por cada fila.
            ->with(['vacuna', 'centro', 'adjuntos'])
            ->get();

        /*
         * La próxima dosis que vale es la de la ÚLTIMA aplicación de cada
         * vacuna: la de una dosis anterior ya se cumplió con la siguiente. Es
         * el mismo criterio que el observer usa para el recordatorio.
         */
        $vacunas = $aplicaciones
            ->groupBy('vacuna_id')
            ->map(function ($dosis): array {
                $ordenadas = $dosis
                    ->sortByDesc(fn (AplicacionVacuna $a): string => $a->fecha->format('Ymd').str_pad((string) $a->id, 10, '0', STR_PAD_LEFT))
                    ->values();

                /** @var AplicacionVacuna $ultima */
                $ultima = $ordenadas->first();

                return [
                    'vacunaId' => $ultima->vacuna_id,
                    'nombre' => $ultima->vacuna->nombre,
                    'ultimaFecha' => $ultima->fecha->format('Ymd'),
                    'proximaDosis' => $ultima->proxima_dosis?->format('Y-m-d'),
                    'proximaDosisVisible' => $ultima->proxima_dosis?->format('d/m/Y'),
                    'dosis' => $ordenadas->map(fn (AplicacionVacuna $a): array => $this->serializar($a))->all(),
                ];
            })
            // La vacuna aplicada más recientemente arriba. En PHP: el nombre está cifrado.
            ->sortByDesc('ultimaFecha')
            ->values()
            ->all();

        return Inertia::render('vacunas-aplicadas/Index', [
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
                'puedeEditar' => $paciente->rolDe($usuario)?->puedeEditar() ?? false,
            ],
            'vacunas' => $vacunas,
            'catalogoVacunas' => $this->disponibles($paciente, Vacuna::class, 'vacuna_id'),
            'centros' => $this->disponibles($paciente, Centro::class, 'centro_id'),
            'hoy' => $usuario?->hoyCalendario()->format('Y-m-d'),
        ]);
    }

    public function store(AplicacionVacunaGuardarRequest $peticion, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('crearEn', [AplicacionVacuna::class, $paciente]);

        $paciente->aplicacionesVacuna()->create($peticion->validated());

        return back()->with('exito', 'Se anotó la dosis.');
    }

    public function update(AplicacionVacunaGuardarRequest $peticion, AplicacionVacuna $aplicacion): RedirectResponse
    {
        Gate::authorize('update', $aplicacion);

        $aplicacion->update($peticion->validated());

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(AplicacionVacuna $aplicacion): RedirectResponse
    {
        Gate::authorize('delete', $aplicacion);

        $aplicacion->delete();

        return back()->with('exito', 'Se eliminó la dosis.');
    }

    /**
     * Lo que esta persona puede ver —propio o semilla— más lo que esta ficha ya
     * viene usando. Tiene que coincidir con lo que valida el FormRequest, o el
     * desplegable ofrecería algo que después se rechaza al guardar.
     *
     * @param  class-string<Vacuna|Centro>  $modelo
     * @return list<array{id: int, nombre: string}>
     */
    private function disponibles(Paciente $paciente, string $modelo, string $columna): array
    {
        $yaUsados = $paciente->aplicacionesVacuna()
            ->whereNotNull($columna)
            ->pluck($columna)
            ->unique()
            ->all();

        return array_values($modelo::query()
            ->where(fn ($consulta) => $consulta
                ->where(CatalogoVisible::para(auth()->id()))
                ->orWhereIn('id', $yaUsados)
            )
            ->get()
            ->map(fn (Model $registro): array => [
                'id' => (int) $registro->getKey(),
                'nombre' => (string) $registro->getAttribute('nombre'),
            ])
            ->sortBy(fn (array $opcion): string => mb_strtolower($opcion['nombre']))
            ->values()
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(AplicacionVacuna $aplicacion): array
    {
        return [
            'id' => $aplicacion->id,
            'vacuna_id' => $aplicacion->vacuna_id,
            'centro_id' => $aplicacion->centro_id,
            'centroNombre' => $aplicacion->centro?->nombre,
            'fecha' => $aplicacion->fecha->format('Y-m-d'),
            'fechaVisible' => $aplicacion->fecha->format('d/m/Y'),
            'proxima_dosis' => $aplicacion->proxima_dosis?->format('Y-m-d'),
            'dosis' => $aplicacion->dosis,
            'lote' => $aplicacion->lote,
            'notas' => $aplicacion->notas,
            'adjuntos' => $aplicacion->adjuntos
                ->sortByDesc('created_at')
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
