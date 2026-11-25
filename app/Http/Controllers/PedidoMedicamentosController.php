<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoEnvio;
use App\Http\Requests\PedidoMedicamentosGuardarRequest;
use App\Http\Requests\PlantillaGenericaGuardarRequest;
use App\Http\Requests\PlantillaPedidoGuardarRequest;
use App\Models\Paciente;
use App\Models\Tratamiento;
use App\Models\User;
use App\Services\EnviadorDeDocumentos;
use App\Services\LibretaDeDestinatarios;
use App\Services\PacientePrincipal;
use App\Services\SeguimientoDePedido;
use App\Support\Destinatario;
use App\Support\PlantillaDePedido;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pedirle por mail los medicamentos (o la receta) al médico, a un centro o a una
 * farmacia. Cada medicamento lleva su propio mensaje, que queda como plantilla
 * de ESE medicamento para el próximo pedido, y el pedido sale por el mismo camino
 * que un envío de documentación: queda en el historial de Contactos.
 */
class PedidoMedicamentosController extends Controller
{
    /** Las dos solapas, en orden: el seguimiento de cada pedido (la de por defecto) y el mail. */
    public const SOLAPAS = ['procedimiento', 'mensajes'];

    public function index(Request $peticion, LibretaDeDestinatarios $libreta, SeguimientoDePedido $seguimiento): Response
    {
        $usuario = $peticion->user();
        assert($usuario instanceof User);

        $paciente = $this->pacienteElegido($peticion, $usuario);
        $solapa = in_array($peticion->query('solapa'), self::SOLAPAS, true) ? (string) $peticion->query('solapa') : self::SOLAPAS[0];

        $tratamientos = $paciente === null ? collect() : $paciente->tratamientos()
            ->with('medicamento')
            ->get()
            // Los que toma ahora primero; en PHP, el nombre está cifrado.
            ->sortBy(fn (Tratamiento $t): string => ($t->activo ? '0' : '1').mb_strtolower($t->medicamento->nombre_comercial))
            ->values();

        /*
         * El medicamento del procedimiento: el pedido, o el primero de la lista.
         * Uno que no es de esta ficha da 404, sin contar de quién es.
         */
        $elegido = null;
        if ($solapa === 'procedimiento' && $paciente !== null) {
            $id = $peticion->integer('medicamento');
            $elegido = $id > 0 ? $tratamientos->firstWhere('id', $id) : $tratamientos->first();
            abort_if($id > 0 && $elegido === null, 404);
        }
        assert($elegido === null || $elegido instanceof Tratamiento);

        return Inertia::render('pedidos/Index', [
            'solapa' => $solapa,
            'medicamentoElegido' => $elegido?->id,
            'procedimiento' => $elegido === null ? null : $seguimiento->vista($elegido),
            'hoy' => $usuario->hoyCalendario()->format('Y-m-d'),
            'paciente' => $paciente === null ? null : [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
                'puedeEditar' => $paciente->rolDe($usuario)?->puedeEditar() ?? false,
                // Para la vista previa, con el mismo corte que el mail (PlantillaDePedido).
                'variables' => PlantillaDePedido::partesDelNombre($paciente->nombre),
            ],
            // El de la cuenta, con sus variables sin reemplazar: es lo que se edita.
            'plantillaGenerica' => PlantillaDePedido::generica($usuario->plantilla_pedido),
            'plantillaOriginal' => PlantillaDePedido::ORIGINAL,
            'fichas' => PacientePrincipal::opciones($usuario),
            'medicamentos' => $paciente === null ? [] : $tratamientos
                ->map(fn (Tratamiento $t): array => [
                    'id' => $t->id,
                    'nombre' => $t->medicamento->nombre_comercial,
                    'dosis' => $t->dosis,
                    'activo' => $t->activo,
                    // Con sus variables: se reemplazan en la vista previa y al mandar.
                    'plantilla' => $t->plantilla_pedido ?? PlantillaDePedido::generica($usuario->plantilla_pedido),
                    'plantillaPropia' => $t->plantilla_pedido !== null,
                ])
                ->all(),
            'destinatarios' => $libreta->para($usuario)
                ->filter(fn (Destinatario $d): bool => in_array($d->origen, PedidoMedicamentosGuardarRequest::ORIGENES, true))
                ->values()
                ->map(fn (Destinatario $d): array => [
                    'clave' => $d->clave(),
                    'nombre' => $d->nombre,
                    'email' => $d->email,
                    'etiqueta' => $d->etiqueta,
                ])
                ->all(),
        ]);
    }

    public function store(PedidoMedicamentosGuardarRequest $peticion, EnviadorDeDocumentos $enviador): RedirectResponse
    {
        $usuario = $peticion->user();
        $paciente = $peticion->paciente();
        $destinatario = $peticion->destinatario();
        assert($usuario instanceof User && $paciente !== null && $destinatario !== null);

        /** @var list<array{tratamiento_id: int|string, mensaje: string}> $pedidos */
        $pedidos = $peticion->validated('pedidos');

        $tratamientos = $paciente->tratamientos()
            ->with('medicamento')
            ->whereIn('id', array_map(fn (array $p): int => (int) $p['tratamiento_id'], $pedidos))
            ->get()
            ->keyBy('id');

        /*
         * En el orden del formulario. Cada plantilla se guarda aunque el mail
         * falle: es lo que la persona escribió, con sus variables. Si es el
         * genérico tal cual, el medicamento queda sin plantilla propia: si no,
         * cambiar el genérico después ya no le llegaría.
         */
        $generica = PlantillaDePedido::generica($usuario->plantilla_pedido);
        $renglones = [];
        foreach ($pedidos as $pedido) {
            $tratamiento = $tratamientos->get((int) $pedido['tratamiento_id']);
            assert($tratamiento instanceof Tratamiento);

            $mensaje = trim($pedido['mensaje']);
            $tratamiento->setAttribute('plantilla_pedido', $mensaje === $generica ? null : $mensaje);
            $tratamiento->save();

            $renglones[] = [
                'medicamento' => $tratamiento->medicamento->nombre_comercial.' · '.$tratamiento->dosis,
                'mensaje' => PlantillaDePedido::completar($mensaje, $tratamiento->medicamento->nombre_comercial, $paciente->nombre),
            ];
        }

        $envio = $enviador->pedir($usuario, $destinatario, $renglones);

        return $envio->estado === EstadoEnvio::Enviado
            ? back()->with('exito', "Se mandó el pedido a {$destinatario->nombre}.")
            : back()->with('error', 'No se pudo mandar el pedido. Quedó anotado en el historial de Contactos: probá de nuevo en un rato.');
    }

    /**
     * Guarda el mensaje como plantilla de ese medicamento, sin mandar nada: vuelve
     * precargado la próxima vez que se lo suma a un pedido.
     */
    public function guardarPlantilla(PlantillaPedidoGuardarRequest $peticion, Tratamiento $tratamiento): RedirectResponse
    {
        Gate::authorize('update', $tratamiento);

        // No es fillable: lo escriben solo el pedido y esta acción.
        $tratamiento->setAttribute('plantilla_pedido', trim((string) $peticion->validated('mensaje')));
        $tratamiento->save();

        return back()->with('exito', "Se guardó el mensaje de {$tratamiento->medicamento->nombre_comercial}.");
    }

    /**
     * El mensaje genérico de la cuenta: el que usa todo medicamento sin plantilla
     * propia, en cualquier ficha. Se guarda con sus variables.
     */
    public function guardarPlantillaGenerica(PlantillaGenericaGuardarRequest $peticion): RedirectResponse
    {
        $usuario = $peticion->user();
        assert($usuario instanceof User);

        $mensaje = trim((string) $peticion->validated('mensaje'));

        // No es fillable. Guardar el original es volver a no tener uno propio.
        $usuario->setAttribute('plantilla_pedido', $mensaje === PlantillaDePedido::ORIGINAL ? null : $mensaje);
        $usuario->save();

        return back()->with('exito', 'Se guardó el mensaje genérico.');
    }

    private function pacienteElegido(Request $peticion, User $usuario): ?Paciente
    {
        $id = $peticion->integer('paciente');

        if ($id > 0) {
            $paciente = Paciente::query()->findOrFail($id);
            Gate::authorize('view', $paciente);

            return $paciente;
        }

        return PacientePrincipal::de($usuario);
    }
}
