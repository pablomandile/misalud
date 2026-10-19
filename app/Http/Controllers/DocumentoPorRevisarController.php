<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoDocumentoPorMail;
use App\Http\Requests\DocumentoPorRevisarGuardarRequest;
use App\Models\DocumentoPorRevisar;
use App\Models\Estudio;
use App\Models\OrdenEstudio;
use App\Models\Paciente;
use App\Models\RecetaMedica;
use App\Models\User;
use App\Services\AdjuntadorDeArchivos;
use App\Services\ArchivoService;
use App\Services\RegistradorDeDocumento;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Resolver lo que quedó por revisar en el buzón: guardarlo en una ficha, o
 * descartarlo.
 */
class DocumentoPorRevisarController extends Controller
{
    public function __construct(
        private readonly RegistradorDeDocumento $registrador,
        private readonly AdjuntadorDeArchivos $adjuntador,
        private readonly ArchivoService $archivos,
    ) {}

    /**
     * Crea la receta, la orden o el estudio con el mismo servicio que el
     * guardado directo, y le PASA los archivos (no los copia): el documento por
     * revisar desaparece y sus archivos quedan colgando del registro nuevo.
     */
    public function guardar(DocumentoPorRevisarGuardarRequest $peticion, DocumentoPorRevisar $documento): RedirectResponse
    {
        Gate::authorize('update', $documento);

        $paciente = Paciente::query()->findOrFail((int) $peticion->validated('paciente_id'));
        $tipo = TipoDocumentoPorMail::from((string) $peticion->validated('tipo'));

        // Y además poder cargar datos en ESA ficha (la regla de siempre).
        Gate::authorize('crearEn', [match ($tipo) {
            TipoDocumentoPorMail::Receta => RecetaMedica::class,
            TipoDocumentoPorMail::Orden => OrdenEstudio::class,
            TipoDocumentoPorMail::Estudio => Estudio::class,
        }, $paciente]);

        $usuario = $this->usuario();
        $local = $usuario->enSuZona($documento->fecha_recepcion) ?? $documento->fecha_recepcion;

        DB::transaction(function () use ($peticion, $documento, $paciente, $tipo, $local): void {
            $registro = $this->registrador->crear(
                $paciente,
                $tipo,
                (string) ($peticion->validated('texto') ?? ''),
                CarbonImmutable::parse($local->toDateString(), 'UTC'),
            );

            $this->adjuntador->traspasar($documento, $registro, $tipo->tipoAdjunto());

            $documento->delete();
        });

        return back()->with('exito', "Se guardó en la ficha de {$paciente->nombre}.");
    }

    /**
     * Descartar borra DE VERDAD, con sus archivos: primero el disco, después
     * las filas. No hay papelera para algo que nunca llegó a una ficha.
     */
    public function destroy(DocumentoPorRevisar $documento): RedirectResponse
    {
        Gate::authorize('delete', $documento);

        $this->archivos->borrarTodosDe($documento);
        $documento->delete();

        return back()->with('exito', 'Se descartó el documento.');
    }

    private function usuario(): User
    {
        $usuario = auth()->user();
        assert($usuario instanceof User);

        return $usuario;
    }
}
