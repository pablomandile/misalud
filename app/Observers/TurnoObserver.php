<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\TipoRecordatorio;
use App\Models\Turno;
use App\Services\GeneradorDeRecordatorios;

/**
 * Mantiene al día el recordatorio de un turno.
 *
 * El observer no decide *cuándo* avisar —eso lo declara
 * `TipoRecordatorio::horasDeAnticipacion()`— ni *cómo* guardarlo —eso es
 * `GeneradorDeRecordatorios`—. Lo único que decide es **cuál es el instante
 * del evento, o si ya no hay evento del que avisar**, y eso lo convierte en
 * cinco líneas que se leen de una sola vez.
 */
class TurnoObserver
{
    public function __construct(private readonly GeneradorDeRecordatorios $recordatorios) {}

    /**
     * `saved` cubre el alta, la edición y también **restaurar** un turno de
     * la papelera: `restore()` termina llamando a `save()`. Por eso no hace
     * falta un método `restored` aparte -y ponerlo haría correr esto dos
     * veces-.
     */
    public function saved(Turno $turno): void
    {
        $this->sincronizar($turno);
    }

    /**
     * Con soft deletes, `deleted` dispara al mandar el turno a la papelera.
     * `runSoftDelete()` escribe por el query builder y **no** pasa por
     * `save()`, así que `saved` no corre acá: los dos caminos no se pisan.
     */
    public function deleted(Turno $turno): void
    {
        $this->recordatorios->sincronizar($turno, TipoRecordatorio::TurnoProximo, null);
    }

    /**
     * ⚠️ **Un turno cancelado no avisa nada**, y esa es la mitad interesante
     * de este archivo: la baja lógica del recordatorio no es solo borrar el
     * turno, es también cancelarlo. Las dos terminan en el mismo lugar
     * -pasar `null`-, que es lo que hace que no haya dos reglas donde
     * debería haber una.
     */
    private function sincronizar(Turno $turno): void
    {
        $this->recordatorios->sincronizar(
            $turno,
            TipoRecordatorio::TurnoProximo,
            $turno->estado->estaVigente() ? $turno->fecha_hora : null,
        );
    }
}
