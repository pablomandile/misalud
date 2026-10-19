<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RolPaciente;
use App\Enums\TipoDocumentoPorMail;
use App\Models\Adjunto;
use App\Models\DocumentoPorRevisar;
use App\Models\Paciente;
use App\Models\User;
use App\Services\ArchivoService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La página de la casilla: a qué dirección reenviar los documentos, cómo
 * escribir el asunto, desde qué correos se los reconoce, y lo que llegó y quedó
 * para revisar.
 *
 * Ya no se configura nada acá: el buzón es uno solo, el de la app, y vive en el
 * `.env` del server (ver `config/misalud.php`).
 */
class CasillaController extends Controller
{
    public function index(): Response
    {
        $usuario = $this->usuario();

        // Las fichas donde puede cargar datos: son a las que puede ir un mail
        // de su cuenta, y entre las que elige al resolver lo pendiente.
        $fichas = $usuario->pacientes()
            ->wherePivotIn('rol', [RolPaciente::Propietario->value, RolPaciente::Cuidador->value])
            ->get()
            ->sortBy(fn (Paciente $paciente): string => mb_strtolower($paciente->nombre))
            ->values();

        $porRevisar = DocumentoPorRevisar::query()
            ->visiblesPara($usuario)
            // Explícito: sin esto es una consulta por cada documento.
            ->with(['adjuntos', 'paciente'])
            ->get()
            ->sortByDesc(fn (DocumentoPorRevisar $documento): int => $documento->fecha_recepcion->getTimestamp())
            ->values();

        return Inertia::render('casilla/Index', [
            'direccion' => config('misalud.buzon.direccion'),
            'maximoMb' => (int) (ArchivoService::MAXIMO_BYTES / 1024 / 1024),
            'tuCorreo' => $usuario->hasVerifiedEmail() ? $usuario->email : null,
            'fichas' => $fichas->map(fn (Paciente $paciente): array => [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
                // El correo de la ficha: la dueña de la ficha también puede
                // mandar desde ahí.
                'email' => $paciente->email,
            ])->all(),
            'tipos' => array_map(
                fn (TipoDocumentoPorMail $tipo): array => ['valor' => $tipo->value, 'etiqueta' => $tipo->etiqueta()],
                TipoDocumentoPorMail::cases(),
            ),
            'porRevisar' => $porRevisar->map(fn (DocumentoPorRevisar $documento): array => [
                'id' => $documento->id,
                'remitente' => $documento->remitente,
                'asunto' => $documento->asunto,
                'motivo' => $documento->motivo->explicacion(),
                'tipoSugerido' => $documento->tipo_sugerido?->value,
                'pacienteId' => $documento->paciente_id,
                'pacienteNombre' => $documento->paciente?->nombre,
                'fechaVisible' => ($usuario->enSuZona($documento->fecha_recepcion) ?? $documento->fecha_recepcion)->format('d/m/Y H:i'),
                'adjuntos' => $documento->adjuntos
                    ->map(fn (Adjunto $adjunto): array => [
                        'id' => $adjunto->id,
                        'nombre' => $adjunto->nombre_original,
                        'mime' => $adjunto->mime,
                        'tamanio' => $adjunto->tamanio_bytes,
                        'url' => route('adjuntos.show', $adjunto),
                    ])
                    ->values()
                    ->all(),
            ])->all(),
            // Las recetas que entraron con la casilla de antes siguen en su
            // bandeja, que salió del menú: se llega desde acá si hay alguna.
            'hayRecetasAnteriores' => $usuario->recetas()->exists(),
        ]);
    }

    private function usuario(): User
    {
        $usuario = auth()->user();
        assert($usuario instanceof User);

        return $usuario;
    }
}
