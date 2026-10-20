<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoAdjunto;
use App\Models\Adjunto;
use App\Models\Cobertura;
use App\Models\Paciente;
use App\Models\User;
use App\Services\PacientePrincipal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Credencial», en el menú: la credencial digital de las coberturas activas
 * del paciente principal (el que comparte el correo con la cuenta), a un toque.
 * Es lo que se busca apurado en un mostrador.
 *
 * Las imágenes van por `credenciales.show` y no por `adjuntos.show`: es la
 * única ruta que el service worker guarda para verlas sin señal.
 */
class CredencialController extends Controller
{
    public function index(Request $peticion): Response
    {
        $usuario = $peticion->user();
        assert($usuario instanceof User);

        $paciente = $this->pacienteElegido($peticion, $usuario);

        return Inertia::render('credencial/Index', [
            'paciente' => $paciente === null ? null : [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
            ],
            'fichas' => PacientePrincipal::opciones($usuario),
            'coberturas' => $paciente === null ? [] : $paciente->coberturas()
                ->with('adjuntos')
                ->where('activa', true)
                ->get()
                ->sortBy(fn (Cobertura $cobertura): string => mb_strtolower((string) $cobertura->entidad))
                ->values()
                ->map(fn (Cobertura $cobertura): array => [
                    'id' => $cobertura->id,
                    'entidad' => $cobertura->entidad,
                    'tipo' => $cobertura->tipo->etiqueta(),
                    'plan' => $cobertura->plan,
                    'nroAfiliado' => $cobertura->nro_afiliado,
                    'telefonoUrgencias' => $cobertura->telefono_urgencias,
                    'credenciales' => $cobertura->adjuntos
                        ->where('tipo', TipoAdjunto::Credencial)
                        ->sortBy('id')
                        ->values()
                        ->map(fn (Adjunto $adjunto): array => [
                            ...$adjunto->paraLista(),
                            'url' => route('credenciales.show', $adjunto),
                        ])
                        ->all(),
                ])
                ->all(),
        ]);
    }

    /**
     * La ficha de la query si la persona la puede ver (si no, 403: no se cae
     * en silencio a otra, que mostraría la credencial de alguien distinto del
     * que se pidió), o la principal.
     */
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
