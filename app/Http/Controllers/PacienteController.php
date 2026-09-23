<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RolPaciente;
use App\Enums\TipoAdjunto;
use App\Http\Requests\PacienteGuardarRequest;
use App\Models\Adjunto;
use App\Models\Cobertura;
use App\Models\Paciente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PacienteController extends Controller
{
    /**
     * Todos los pacientes a los que el usuario tiene acceso.
     *
     * `nombre` está cifrado: no hay `orderBy` en SQL, se trae todo (son
     * decenas de filas por usuario como mucho) y se ordena acá.
     */
    public function index(): Response
    {
        $pacientes = auth()->user()->pacientes()
            // Explicito: sin esto es una consulta por cada paciente y cada cobertura.
            ->with(['adjuntos', 'coberturas.adjuntos'])
            ->get()
            ->sortBy(fn (Paciente $paciente): string => $paciente->nombre)
            ->values()
            ->map(fn (Paciente $paciente): array => $this->serializar($paciente))
            ->all();

        return Inertia::render('pacientes/Index', [
            'pacientes' => $pacientes,
        ]);
    }

    public function store(PacienteGuardarRequest $request): RedirectResponse
    {
        $paciente = Paciente::create([
            ...$request->validated(),
            'usuario_id' => auth()->id(),
        ]);

        return back()->with('exito', "Se agregó a {$paciente->nombre}.");
    }

    public function update(PacienteGuardarRequest $request, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('update', $paciente);

        $paciente->update($request->validated());

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(Paciente $paciente): RedirectResponse
    {
        Gate::authorize('delete', $paciente);

        $nombre = $paciente->nombre;
        $paciente->delete();

        // Si era el activo, la sesión queda apuntando a un id que ya no está.
        if (session('paciente_activo_id') === $paciente->id) {
            session()->forget('paciente_activo_id');
        }

        return back()->with('exito', "Se eliminó a {$nombre}.");
    }

    /**
     * @return array{id: int, nombre: string, fecha_nacimiento: string|null, edad: int|null, sexo: string|null, grupo_sanguineo: string|null, notas: string|null, puedeEditar: bool, esPropietario: bool}
     */
    private function serializar(Paciente $paciente): array
    {
        $rol = $paciente->rolDe(auth()->user());

        return [
            'id' => $paciente->id,
            'nombre' => $paciente->nombre,
            'fecha_nacimiento' => $paciente->fecha_nacimiento?->format('Y-m-d'),
            'edad' => $paciente->edad,
            'sexo' => $paciente->sexo,
            'grupo_sanguineo' => $paciente->grupo_sanguineo,
            'notas' => $paciente->notas,
            'puedeEditar' => $rol?->puedeEditar() ?? false,
            'esPropietario' => $rol === RolPaciente::Propietario,

            /*
             * Los documentos van con la URL del CONTROLADOR, nunca una del
             * disco: estos archivos están cifrados y no son públicos. La URL
             * igual no alcanza sola -la Policy se vuelve a consultar al
             * servirlos-, pero no hay que dar de más.
             */
            'adjuntos' => $paciente->adjuntos
                ->sortByDesc('created_at')
                ->values()
                ->map(fn (Adjunto $adjunto): array => [
                    'id' => $adjunto->id,
                    'nombre' => $adjunto->nombre_original,
                    'mime' => $adjunto->mime,
                    'tamanio' => $adjunto->tamanio_bytes,
                    'tipo' => $adjunto->tipo->etiqueta(),
                    'url' => route('adjuntos.show', $adjunto),
                ])
                ->all(),

            'coberturas' => $paciente->coberturas
                // activas primero, y entre iguales la más nueva: es la que
                // se ofrece por defecto al cargar un estudio o un turno.
                ->sortByDesc(fn (Cobertura $c): string => ($c->activa ? '1' : '0').$c->created_at->timestamp)
                ->values()
                ->map(fn (Cobertura $cobertura): array => [
                    'id' => $cobertura->id,
                    'tipo' => $cobertura->tipo->value,
                    'tipoEtiqueta' => $cobertura->tipo->etiqueta(),
                    'entidad' => $cobertura->entidad,
                    'plan' => $cobertura->plan,
                    'nro_afiliado' => $cobertura->nro_afiliado,
                    'telefono' => $cobertura->telefono,
                    'telefono_urgencias' => $cobertura->telefono_urgencias,
                    'sitio_web' => $cobertura->sitio_web,
                    'vigencia_desde' => $cobertura->vigencia_desde?->format('Y-m-d'),
                    'vigencia_hasta' => $cobertura->vigencia_hasta?->format('Y-m-d'),
                    'activa' => $cobertura->activa,
                    'notas' => $cobertura->notas,
                    // La credencial, frente y dorso: adjuntos tipo `credencial`
                    // colgados de la COBERTURA, no del paciente.
                    'adjuntos' => $cobertura->adjuntos
                        ->sortByDesc('created_at')
                        ->values()
                        ->map(fn (Adjunto $adjunto): array => [
                            'id' => $adjunto->id,
                            'nombre' => $adjunto->nombre_original,
                            'mime' => $adjunto->mime,
                            'tamanio' => $adjunto->tamanio_bytes,
                            'tipo' => $adjunto->tipo->etiqueta(),
                            /*
                             * Por tipo y no siempre `credenciales.show`: hoy
                             * todo lo que cuelga de una cobertura es
                             * credencial, pero la ruta cacheable por el
                             * service worker RECHAZA cualquier otra cosa (ver
                             * AdjuntoController::showCredencial). Si el día de
                             * mañana algo no-credencial termina colgado acá,
                             * que siga sirviéndose, solo que sin cachear.
                             */
                            'url' => $adjunto->tipo === TipoAdjunto::Credencial
                                ? route('credenciales.show', $adjunto)
                                : route('adjuntos.show', $adjunto),
                        ])
                        ->all(),
                ])
                ->all(),
        ];
    }
}
