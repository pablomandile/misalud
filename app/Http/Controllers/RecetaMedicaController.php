<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\EsCatalogo;
use App\Enums\EstadoReceta;
use App\Enums\TipoAdjunto;
use App\Http\Requests\RecetaMedicaGuardarRequest;
use App\Http\Requests\RecetaMedicaUsoRequest;
use App\Models\Adjunto;
use App\Models\Medicamento;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\RecetaMedica;
use App\Models\User;
use App\Services\AdjuntadorDeArchivos;
use App\Support\CatalogoVisible;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las recetas de medicamentos de una ficha: el papel que firma el médico para
 * comprar en la farmacia.
 *
 * La pantalla contesta lo mismo que la bandeja de recetas: **¿qué receta puedo
 * usar hoy?** Las disponibles arriba, de la que vence primero a la que vence
 * último (una cuenta regresiva); debajo, las usadas y las vencidas. La
 * separación la hace el servidor: "vencida" depende del día, y con el reloj del
 * celular corrido una vencida aparecería como usable justo en el mostrador.
 */
class RecetaMedicaController extends Controller
{
    public function __construct(private readonly AdjuntadorDeArchivos $adjuntador) {}

    public function index(Paciente $paciente): Response
    {
        Gate::authorize('view', $paciente);

        $usuario = $this->usuario();

        $recetas = $paciente->recetasMedicas()
            // Explícito: sin esto es una consulta por cada fila del listado.
            ->with(['medico', 'medicamento', 'adjuntos'])
            ->get();

        [$disponibles, $historial] = $recetas->partition(
            fn (RecetaMedica $receta): bool => $receta->estaDisponible($usuario),
        );

        return Inertia::render('recetas-medicas/Index', [
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
                'puedeEditar' => $paciente->rolDe($usuario)?->puedeEditar() ?? false,
            ],
            'disponibles' => $disponibles
                ->sortBy(fn (RecetaMedica $receta): string => $receta->vence()->format('Ymd'))
                ->values()
                ->map(fn (RecetaMedica $receta): array => $this->serializar($receta, $usuario))
                ->all(),
            'historial' => $historial
                ->sortByDesc(fn (RecetaMedica $receta): string => $receta->fecha->format('Ymd'))
                ->values()
                ->map(fn (RecetaMedica $receta): array => $this->serializar($receta, $usuario))
                ->all(),
            'medicos' => $this->disponibles(Medico::class, $paciente, 'medico_id'),
            'medicamentos' => $this->disponibles(Medicamento::class, $paciente, 'medicamento_id'),
            'hoy' => $usuario->hoyCalendario()->format('Y-m-d'),
        ]);
    }

    public function store(RecetaMedicaGuardarRequest $peticion, Paciente $paciente): RedirectResponse
    {
        Gate::authorize('crearEn', [RecetaMedica::class, $paciente]);

        // La receta y su papel juntos o nada.
        DB::transaction(function () use ($peticion, $paciente): void {
            $receta = $paciente->recetasMedicas()->create($peticion->safe()->except('archivos'));

            $this->adjuntador->adjuntar($receta, $peticion->file('archivos', []), TipoAdjunto::Receta);
        });

        return back()->with('exito', 'Se agregó la receta.');
    }

    public function update(RecetaMedicaGuardarRequest $peticion, RecetaMedica $recetaMedica): RedirectResponse
    {
        Gate::authorize('update', $recetaMedica);

        $recetaMedica->update($peticion->safe()->except('archivos'));

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    /**
     * Deshacer el uso no le devuelve la vigencia: lo que se deshace es la
     * marca, no el paso del tiempo.
     */
    public function uso(RecetaMedicaUsoRequest $peticion, RecetaMedica $recetaMedica): RedirectResponse
    {
        Gate::authorize('update', $recetaMedica);

        $usada = $peticion->boolean('usada');

        // `estado` no es fillable: solo lo mueve esta acción.
        $recetaMedica->setAttribute('estado', $usada ? EstadoReceta::Usada : EstadoReceta::Disponible);
        $recetaMedica->save();

        return back()->with('exito', $usada ? 'Se marcó como usada.' : 'Volvió a las disponibles.');
    }

    public function destroy(RecetaMedica $recetaMedica): RedirectResponse
    {
        Gate::authorize('delete', $recetaMedica);

        $recetaMedica->delete();

        return back()->with('exito', 'Se eliminó la receta.');
    }

    /**
     * Lo que esta persona ve -propio o semilla- más lo que esta ficha ya viene
     * usando. Tiene que coincidir con lo que valida el FormRequest.
     *
     * @param  class-string<Medico|Medicamento>  $modelo
     * @return list<array{id: int, nombre: string}>
     */
    private function disponibles(string $modelo, Paciente $paciente, string $columna): array
    {
        $yaUsados = $paciente->recetasMedicas()
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
            ->map(fn (Model&EsCatalogo $registro): array => [
                'id' => (int) $registro->getKey(),
                'nombre' => $registro->nombreVisible(),
            ])
            // En PHP: el nombre está cifrado.
            ->sortBy(fn (array $fila): string => mb_strtolower($fila['nombre']))
            ->values()
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(RecetaMedica $receta, User $usuario): array
    {
        $vence = $receta->vence();
        $hoy = $usuario->hoyCalendario();
        $dias = (int) $hoy->diffInDays($vence, false);

        return [
            'id' => $receta->id,
            'medicamento_id' => $receta->medicamento_id,
            'medicamentoNombre' => $receta->medicamento?->nombreVisible(),
            'detalle' => $receta->detalle,
            'fecha' => $receta->fecha->format('Y-m-d'),
            'fechaVisible' => $receta->fecha->format('d/m/Y'),
            'vigencia_dias' => $receta->vigencia_dias,
            'usada' => $receta->estado === EstadoReceta::Usada,
            /*
             * Con palabras y sin color de alarma: es un dato, no un juicio
             * (regla 1). Días de CALENDARIO de la persona, medianoche contra
             * medianoche.
             */
            'venceTexto' => match (true) {
                $receta->estado === EstadoReceta::Usada => 'Usada',
                $dias < 0 => 'Venció el '.$vence->format('d/m/Y'),
                $dias === 0 => 'Vence hoy',
                $dias === 1 => 'Vence mañana',
                default => 'Vence el '.$vence->format('d/m/Y')." (en {$dias} días)",
            },
            'medico_id' => $receta->medico_id,
            'medicoNombre' => $receta->medico?->nombre,
            'notas' => $receta->notas,
            'adjuntos' => $receta->adjuntos
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

    private function usuario(): User
    {
        $usuario = auth()->user();
        assert($usuario instanceof User);

        return $usuario;
    }
}
