<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PeriodicidadToma;
use App\Models\Tratamiento;
use App\Observers\TratamientoObserver;
use Illuminate\Console\Command;

/**
 * Pasa al año siguiente los avisos de un medicamento de "una vez por año"
 * cuando su aplicación ya pasó.
 *
 * La fecha no se guarda: sale del día y el mes (`Tratamiento::proximaAplicacion()`),
 * así que nada cambia en la fila cuando la fecha pasa y el observer no corre.
 * Este comando, una vez al día, le pide al observer el mismo cálculo. Es
 * idempotente y barato: el generador no escribe nada si la fecha no cambió.
 *
 * Hoy en UTC, la misma excepción consciente que `misalud:cerrar-tratamientos-vencidos`.
 */
class RenovarAplicacionesAnuales extends Command
{
    protected $signature = 'misalud:renovar-aplicaciones-anuales';

    protected $description = 'Mueve al año siguiente los avisos de las aplicaciones anuales que ya pasaron';

    public function handle(TratamientoObserver $observer): int
    {
        $revisados = 0;

        Tratamiento::query()
            ->where('activo', true)
            ->where('periodicidad', PeriodicidadToma::Anual)
            ->whereNotNull('aplicacion_dia')
            ->whereNotNull('aplicacion_mes')
            ->lazyById()
            ->each(function (Tratamiento $tratamiento) use ($observer, &$revisados): void {
                $observer->sincronizarAplicacion($tratamiento);
                $revisados++;
            });

        $this->components->info("Se revisaron {$revisados} medicamento(s) de una vez por año.");

        return self::SUCCESS;
    }
}
