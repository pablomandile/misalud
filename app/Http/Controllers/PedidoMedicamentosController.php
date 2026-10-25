<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoEnvio;
use App\Http\Requests\PedidoMedicamentosGuardarRequest;
use App\Models\Paciente;
use App\Models\Tratamiento;
use App\Models\User;
use App\Services\EnviadorDeDocumentos;
use App\Services\LibretaDeDestinatarios;
use App\Services\PacientePrincipal;
use App\Support\Destinatario;
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
    public function index(Request $peticion, LibretaDeDestinatarios $libreta): Response
    {
        $usuario = $peticion->user();
        assert($usuario instanceof User);

        $paciente = $this->pacienteElegido($peticion, $usuario);

        return Inertia::render('pedidos/Index', [
            'paciente' => $paciente === null ? null : [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
                'puedeEditar' => $paciente->rolDe($usuario)?->puedeEditar() ?? false,
            ],
            'fichas' => PacientePrincipal::opciones($usuario),
            'medicamentos' => $paciente === null ? [] : $paciente->tratamientos()
                ->with('medicamento')
                ->get()
                // Los que toma ahora primero; en PHP, el nombre está cifrado.
                ->sortBy(fn (Tratamiento $t): string => ($t->activo ? '0' : '1').mb_strtolower($t->medicamento->nombre_comercial))
                ->values()
                ->map(fn (Tratamiento $t): array => [
                    'id' => $t->id,
                    'nombre' => $t->medicamento->nombre_comercial,
                    'dosis' => $t->dosis,
                    'activo' => $t->activo,
                    'plantilla' => $this->plantillaDe($t, $paciente),
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

        // En el orden del formulario. Cada plantilla se guarda aunque el mail
        // falle: es lo que la persona escribió.
        $renglones = [];
        foreach ($pedidos as $pedido) {
            $tratamiento = $tratamientos->get((int) $pedido['tratamiento_id']);
            assert($tratamiento instanceof Tratamiento);

            $mensaje = trim($pedido['mensaje']);
            $tratamiento->setAttribute('plantilla_pedido', $mensaje);
            $tratamiento->save();

            $renglones[] = [
                'medicamento' => $tratamiento->medicamento->nombre_comercial.' · '.$tratamiento->dosis,
                'mensaje' => $mensaje,
            ];
        }

        $envio = $enviador->pedir($usuario, $destinatario, $renglones);

        return $envio->estado === EstadoEnvio::Enviado
            ? back()->with('exito', "Se mandó el pedido a {$destinatario->nombre}.")
            : back()->with('error', 'No se pudo mandar el pedido. Quedó anotado en el historial de Contactos: probá de nuevo en un rato.');
    }

    private function plantillaDe(Tratamiento $tratamiento, Paciente $paciente): string
    {
        return $tratamiento->plantilla_pedido
            ?? "Hola, ¿me podrías hacer la receta de {$tratamiento->medicamento->nombre_comercial} para {$paciente->nombre}? Gracias.";
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
