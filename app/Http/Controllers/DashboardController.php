<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoAdjunto;
use App\Models\Adjunto;
use App\Models\Cobertura;
use App\Models\Paciente;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel principal.
 *
 * Por ahora resuelve un solo acceso rápido -la credencial de la obra
 * social del paciente activo-, que es el paso 4.2 del plan. El resto
 * (recetas disponibles, tratamientos, turnos, órdenes pendientes, últimas
 * mediciones) llega en la Etapa 15, cuando esos módulos existan: no tiene
 * sentido armar el layout final del dashboard con cuatro quintos de las
 * tarjetas vacías.
 */
class DashboardController extends Controller
{
    public function index(): Response
    {
        $paciente = $this->pacienteActivo();

        return Inertia::render('Dashboard', [
            'pacienteActivo' => $paciente === null ? null : [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
            ],
            'credenciales' => $paciente === null ? [] : $this->credencialesDe($paciente),
        ]);
    }

    /**
     * El paciente activo de la sesión, o -sin selección todavía, que es el
     * caso de cualquiera que no haya tocado nunca un selector que ni existe
     * en pantalla- el primero por nombre, con el mismo criterio de orden
     * que `PacienteController::index`.
     *
     * `nombre` está cifrado: no hay `orderBy` en SQL (ver CLAUDE.md), así
     * que se trae todo -son decenas de filas por usuario, como mucho- y se
     * ordena en PHP.
     */
    private function pacienteActivo(): ?Paciente
    {
        $pacientes = auth()->user()->pacientes()
            ->with('coberturas.adjuntos')
            ->get();

        $activoId = session('paciente_activo_id');

        if ($activoId !== null) {
            $elegido = $pacientes->firstWhere('id', $activoId);

            if ($elegido !== null) {
                return $elegido;
            }
        }

        return $pacientes->sortBy(fn (Paciente $paciente): string => $paciente->nombre)->first();
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
}
