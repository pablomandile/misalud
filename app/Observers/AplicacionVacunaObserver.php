<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\TipoRecordatorio;
use App\Models\AplicacionVacuna;
use App\Services\GeneradorDeRecordatorios;

/**
 * Avisa la próxima dosis de una vacuna.
 *
 * ## ⚠️ La próxima dosis de una aplicación deja de importar cuando llega la siguiente
 *
 * Si la primera dosis decía "próxima: 15/3" y la segunda ya se cargó, ese
 * aviso no tiene nada que avisar —ya pasó lo que anunciaba—, aunque la
 * primera fila siga diciendo "15/3". Por eso el aviso no se decide mirando
 * solo la fila que se guardó: **se recalcula el carnet entero del paciente**
 * cada vez que cambia una dosis. Eso cubre de una sola vez los casos que de
 * otra forma serían cuatro reglas sueltas:
 *
 * - cargar la segunda dosis apaga el aviso de la primera;
 * - borrar la segunda lo vuelve a prender;
 * - cambiarle la vacuna a una fila mueve el aviso de un grupo al otro;
 * - corregir la fecha de una dosis reordena cuál es "la siguiente".
 *
 * Son decenas de filas por paciente, y `GeneradorDeRecordatorios` no escribe
 * nada si la fecha no cambió: recalcular todo es barato e idempotente.
 *
 * "Posterior" se decide por `fecha` y, en empate —dos dosis el mismo día—,
 * por `id`: sin desempate las dos se taparían la una a la otra.
 */
class AplicacionVacunaObserver
{
    public function __construct(private readonly GeneradorDeRecordatorios $recordatorios) {}

    public function saved(AplicacionVacuna $aplicacion): void
    {
        $this->recalcularCarnet($aplicacion->paciente_id);
    }

    public function deleted(AplicacionVacuna $aplicacion): void
    {
        // La borrada ya no avisa nada; y si era "la siguiente" de otra, esa otra
        // vuelve a avisar. El recálculo no la ve (está en la papelera).
        $this->recordatorios->sincronizar($aplicacion, TipoRecordatorio::VacunaProxima, null);
        $this->recalcularCarnet($aplicacion->paciente_id);
    }

    private function recalcularCarnet(int $pacienteId): void
    {
        $carnet = AplicacionVacuna::query()
            ->where('paciente_id', $pacienteId)
            ->get();

        foreach ($carnet as $dosis) {
            $yaHayOtraDespues = $carnet->contains(
                fn (AplicacionVacuna $otra): bool => $otra->vacuna_id === $dosis->vacuna_id
                    && ($otra->fecha->gt($dosis->fecha)
                        || ($otra->fecha->equalTo($dosis->fecha) && $otra->id > $dosis->id)),
            );

            $this->recordatorios->sincronizar(
                $dosis,
                TipoRecordatorio::VacunaProxima,
                $yaHayOtraDespues ? null : $dosis->proxima_dosis,
            );
        }
    }
}
