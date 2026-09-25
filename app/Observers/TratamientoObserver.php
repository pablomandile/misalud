<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\TipoRecordatorio;
use App\Models\Tratamiento;
use App\Services\GeneradorDeRecordatorios;

/**
 * Avisa cuando un tratamiento está por terminar.
 *
 * Es el segundo origen de recordatorios, y está acá justamente para eso: con
 * uno solo, `GeneradorDeRecordatorios` sería una abstracción sin evidencia.
 *
 * ## ⚠️ `misalud:cerrar-tratamientos-vencidos` NO dispara este observer
 *
 * Ese comando escribe con un `update()` masivo, que no pasa por Eloquent y
 * por lo tanto no llama a ningún observer. **No se cambió**, y vale explicar
 * por qué no es un descuido: para cuando el comando cierra un tratamiento,
 * su `fin` **ya pasó**, así que el recordatorio correspondiente venció hace
 * rato y el comando horario lo va a descartar solo (ver
 * `EnviarRecordatorios`). Cargar cientos de modelos para producir un cambio
 * que no se nota sería peor que dejarlo así.
 */
class TratamientoObserver
{
    public function __construct(private readonly GeneradorDeRecordatorios $recordatorios) {}

    public function saved(Tratamiento $tratamiento): void
    {
        $this->sincronizar($tratamiento);
    }

    public function deleted(Tratamiento $tratamiento): void
    {
        $this->recordatorios->sincronizar(
            $tratamiento,
            TipoRecordatorio::TratamientoTermina,
            null,
        );
    }

    /**
     * Dos condiciones para que haya aviso, y las dos por el mismo motivo: un
     * tratamiento **sin fecha de fin** no termina nunca -es un crónico, no
     * hay nada que recordar-, y uno **ya inactivo** tampoco -se suspendió
     * antes de llegar al final-.
     */
    private function sincronizar(Tratamiento $tratamiento): void
    {
        $corresponde = $tratamiento->activo && $tratamiento->fin !== null;

        $this->recordatorios->sincronizar(
            $tratamiento,
            TipoRecordatorio::TratamientoTermina,
            $corresponde ? $tratamiento->fin : null,
        );
    }
}
