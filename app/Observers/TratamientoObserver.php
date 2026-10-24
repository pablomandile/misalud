<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\TipoRecordatorio;
use App\Models\Tratamiento;
use App\Services\GeneradorDeRecordatorios;
use Carbon\CarbonImmutable;

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
    private const AVISOS_DE_APLICACION = [
        TipoRecordatorio::AplicacionAnualMes,
        TipoRecordatorio::AplicacionAnualSemana,
    ];

    public function __construct(private readonly GeneradorDeRecordatorios $recordatorios) {}

    public function saved(Tratamiento $tratamiento): void
    {
        $this->sincronizar($tratamiento);
    }

    public function deleted(Tratamiento $tratamiento): void
    {
        foreach ([TipoRecordatorio::TratamientoTermina, ...self::AVISOS_DE_APLICACION] as $tipo) {
            $this->recordatorios->sincronizar($tratamiento, $tipo, null);
        }
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

        $this->sincronizarAplicacion($tratamiento);
    }

    /**
     * Un medicamento de "una vez por año" avisa de su próxima aplicación un mes
     * antes y una semana antes. La fecha no se guarda: sale del día y el mes
     * (`Tratamiento::proximaAplicacion()`), y por eso también la llama todos
     * los días `misalud:renovar-aplicaciones-anuales`, que pasa los avisos al
     * año siguiente cuando la fecha ya pasó. Hoy en UTC: es un proceso de
     * fondo, sin un usuario del que tomar la zona (como el cierre de vencidos).
     *
     * Sin día y mes, con otra toma o suspendido, los dos se limpian. Y **un
     * aviso cuya fecha ya pasó no se genera**: cargar la aplicación cuatro días
     * antes no puede mandar «en un mes toca…».
     */
    public function sincronizarAplicacion(Tratamiento $tratamiento): void
    {
        $aplicacion = $tratamiento->activo
            ? $tratamiento->proximaAplicacion(CarbonImmutable::now('UTC'))
            : null;

        foreach (self::AVISOS_DE_APLICACION as $tipo) {
            $this->recordatorios->sincronizar(
                $tratamiento,
                $tipo,
                $aplicacion !== null && $this->correspondeAvisar($tratamiento, $tipo, $aplicacion) ? $aplicacion : null,
            );
        }
    }

    /**
     * Un aviso cuya fecha ya pasó no se CREA, pero el que ya existe para esa
     * misma fecha se deja: es el que salió (o venció) este año, y borrarlo al
     * día siguiente lo sacaría de la pantalla de avisos.
     */
    private function correspondeAvisar(Tratamiento $tratamiento, TipoRecordatorio $tipo, CarbonImmutable $aplicacion): bool
    {
        $aviso = $aplicacion->subHours($tipo->horasDeAnticipacion());

        if ($aviso->greaterThanOrEqualTo(CarbonImmutable::now('UTC')->startOfDay())) {
            return true;
        }

        $existente = $tratamiento->recordatorios()->where('tipo', $tipo)->first();

        return $existente !== null && $existente->fecha->format('Y-m-d H:i:s') === $aviso->format('Y-m-d H:i:s');
    }
}
