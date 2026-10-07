<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PerteneceAPaciente;
use App\Models\Adjunto;
use App\Models\AplicacionVacuna;
use App\Models\Cobertura;
use App\Models\Estudio;
use App\Models\OrdenEstudio;
use App\Models\Paciente;
use App\Models\PrescripcionOcular;
use App\Models\Receta;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Qué más se puede mandar junto con un documento.
 *
 * Un envío arranca desde un documento puntual —el botón "Enviar" al lado de una
 * orden—, pero casi nunca termina en uno: la obra social pide la orden **y** la
 * credencial, la óptica la receta de anteojos **y** la orden del oftalmólogo.
 * Esto arma la lista para sumar, con un criterio de **ámbito**:
 *
 * - un documento de un paciente ofrece **los de ese mismo paciente** —su ficha,
 *   sus coberturas, órdenes, estudios y recetas de anteojos—, nunca los de otro
 *   familiar: mezclar fichas en un mismo mail es el error que más caro sale;
 * - una receta importada ofrece las otras recetas de quien tiene la casilla.
 *
 * ⚠️ **Cada candidato pasa por la Policy**, aunque haya salido de una consulta
 * acotada. La consulta decide qué es *pertinente*; quién puede verlo lo decide
 * siempre `AdjuntoPolicy`, y esta lista no es la excepción. Y al mandar se vuelve a
 * autorizar archivo por archivo (`EnvioGuardarRequest`): la lista que vio la
 * pantalla no es una autorización.
 */
class DocumentosEnviables
{
    /**
     * @return Collection<int, Adjunto>
     */
    public function junto(User $usuario, Adjunto $inicial): Collection
    {
        $duenio = $inicial->adjuntable;

        $candidatos = match (true) {
            $duenio instanceof Receta => $this->deRecetas($usuario),
            default => ($paciente = $this->pacienteDe($inicial)) !== null
                ? $this->dePaciente($paciente)
                // Un prospecto, o un dueño sin paciente: va solo.
                : collect([$inicial]),
        };

        return $candidatos
            ->filter(fn (Adjunto $adjunto): bool => Gate::forUser($usuario)->allows('view', $adjunto))
            ->values();
    }

    /**
     * El paciente del que habla un documento, si habla de uno.
     */
    public function pacienteDe(Adjunto $adjunto): ?Paciente
    {
        $duenio = $adjunto->adjuntable;

        return match (true) {
            $duenio instanceof Paciente => $duenio,
            $duenio instanceof PerteneceAPaciente => $duenio->pacienteDelRegistro(),
            default => null,
        };
    }

    /**
     * Cómo lo reconoce una persona: "Orden de estudio · Ecografía", no
     * "IMG_2031.jpg".
     *
     * El nombre del archivo no alcanza —lo eligió el celular o el que lo mandó—,
     * así que se le suma el dato de su dueño que lo identifica en la pantalla de
     * donde salió.
     */
    public function describir(Adjunto $adjunto): string
    {
        $duenio = $adjunto->adjuntable;

        $de = match (true) {
            $duenio instanceof Cobertura => $duenio->entidad,
            $duenio instanceof OrdenEstudio => $duenio->estudio_solicitado,
            $duenio instanceof Estudio => $duenio->tipo,
            $duenio instanceof PrescripcionOcular => 'del '.$duenio->fecha->format('d/m/Y'),
            $duenio instanceof Receta => $duenio->asunto,
            $duenio instanceof AplicacionVacuna => $duenio->vacuna->nombre.' del '.$duenio->fecha->format('d/m/Y'),
            default => null,
        };

        $tipo = $adjunto->tipo->etiqueta();

        return $de === null || $de === '' ? $tipo : "{$tipo} · {$de}";
    }

    /**
     * Todo lo que tiene archivos y cuelga de un paciente, más la ficha misma.
     *
     * Sale de las **relaciones** del paciente y no de un `where paciente_id`
     * sobre cada tabla: así las coberturas, órdenes o estudios que están en la
     * papelera quedan afuera solos (las relaciones respetan el soft delete), y un
     * documento de algo borrado no se ofrece para mandar.
     *
     * @return Collection<int, Adjunto>
     */
    private function dePaciente(Paciente $paciente): Collection
    {
        // Tipo de dueño => sus ids, como subconsulta (la relación ya trae el
        // `where paciente_id` y el filtro de la papelera).
        $relaciones = [
            (new Cobertura)->getMorphClass() => $paciente->coberturas()->getQuery()->select('id'),
            (new OrdenEstudio)->getMorphClass() => $paciente->ordenesEstudio()->getQuery()->select('id'),
            (new Estudio)->getMorphClass() => $paciente->estudios()->getQuery()->select('id'),
            (new PrescripcionOcular)->getMorphClass() => $paciente->prescripcionesOculares()->getQuery()->select('id'),
            (new AplicacionVacuna)->getMorphClass() => $paciente->aplicacionesVacuna()->getQuery()->select('id'),
        ];

        return Adjunto::query()
            ->with('adjuntable')
            ->where(function (Builder $consulta) use ($paciente, $relaciones): void {
                $consulta->where(fn (Builder $q) => $q
                    ->where('adjuntable_type', $paciente->getMorphClass())
                    ->where('adjuntable_id', $paciente->getKey()));

                foreach ($relaciones as $tipo => $ids) {
                    $consulta->orWhere(fn (Builder $q) => $q
                        ->where('adjuntable_type', $tipo)
                        ->whereIn('adjuntable_id', $ids));
                }
            })
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @return Collection<int, Adjunto>
     */
    private function deRecetas(User $usuario): Collection
    {
        return Adjunto::query()
            ->with('adjuntable')
            ->where('adjuntable_type', (new Receta)->getMorphClass())
            ->whereIn('adjuntable_id', $usuario->recetas()->getQuery()->select('id'))
            ->orderByDesc('created_at')
            ->get();
    }
}
