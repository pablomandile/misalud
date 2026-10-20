<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Adjunto;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La pantalla de Documentos de una ficha: los archivos sueltos del paciente
 * (los que no cuelgan de una orden, un estudio o una cobertura).
 *
 * Es una pantalla propia y no un panel lateral, igual que el resto de las
 * secciones de la ficha: el visor a pantalla completa se abría desde adentro
 * del panel, y dos capas modales una encima de la otra se peleaban el foco y el
 * toque. Subir, renombrar y borrar siguen yendo por `AdjuntoController`.
 */
class DocumentoController extends Controller
{
    public function index(Paciente $paciente): Response
    {
        Gate::authorize('view', $paciente);

        $usuario = auth()->user();
        assert($usuario instanceof User);

        return Inertia::render('documentos/Index', [
            'paciente' => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
                'puedeEditar' => $paciente->rolDe($usuario)?->puedeEditar() ?? false,
            ],
            'documentos' => $paciente->adjuntos()
                ->get()
                ->sortByDesc('created_at')
                ->values()
                ->map(fn (Adjunto $adjunto): array => $adjunto->paraLista())
                ->all(),
        ]);
    }
}
