<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PerteneceAPaciente;
use App\Enums\EstadoRecordatorio;
use App\Enums\TipoRecordatorio;
use App\Models\Recordatorio;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * El único lugar donde nace, se mueve o muere un recordatorio.
 *
 * Existe porque **los observers no pueden tener cada uno su propia copia de
 * esto**: la idempotencia no es una línea de código, son cuatro decisiones
 * que hay que tomar igual en todos los orígenes, y la segunda copia sería
 * donde una de las cuatro sale distinta.
 *
 * ## El contrato, entero
 *
 * `sincronizar()` recibe el origen, el tipo y **el instante del evento**, y
 * deja la base en el estado que corresponde. Se puede llamar mil veces
 * seguidas: eso es exactamente lo que hace un observer, que corre en cada
 * guardado del origen.
 *
 * | Lo que llega                          | Lo que hace                              |
 * | ------------------------------------- | ---------------------------------------- |
 * | instante `null`                       | **Borra** el recordatorio si existía     |
 * | no existe todavía                     | Lo crea, `Pendiente`                     |
 * | existe y la fecha NO cambió           | **Nada**, ni un `touch`                  |
 * | existe y la fecha cambió              | La actualiza y lo **reabre**             |
 *
 * ## ⚠️ Las dos filas del medio son el corazón del asunto
 *
 * **Si la fecha no cambió, no se toca nada.** Un observer corre también
 * cuando alguien edita el motivo del turno, y si eso reseteara el estado, un
 * recordatorio que la persona ya marcó como hecho volvería a aparecer como
 * pendiente por haber corregido una falta de ortografía.
 *
 * **Si la fecha cambió, se reabre** —estado a `Pendiente` y `enviado_en` a
 * `null`—, y eso es igual de deliberado: el turno se movió de día, así que
 * el "ya lo sé" que la persona dio antes era sobre otra fecha, y el aviso
 * que ya se mandó decía un día que ya no es. Hay que volver a avisar.
 */
class GeneradorDeRecordatorios
{
    /**
     * @param  Model&PerteneceAPaciente  $origen  la fila que motiva el aviso
     * @param  DateTimeInterface|null  $instanteDelEvento  `null` = ya no corresponde avisar
     */
    public function sincronizar(
        Model&PerteneceAPaciente $origen,
        TipoRecordatorio $tipo,
        ?DateTimeInterface $instanteDelEvento,
    ): void {
        $existente = $this->buscar($origen, $tipo);
        $paciente = $origen->pacienteDelRegistro();

        /*
         * Sin evento no hay aviso, y sin paciente tampoco: un recordatorio
         * cuyo `paciente_id` no se puede resolver sería una fila que nadie
         * puede ver ni borrar -la Policy trata el paciente nulo como "no"-.
         * En los dos casos se limpia lo que hubiera.
         */
        if ($instanteDelEvento === null || $paciente === null) {
            $existente?->delete();

            return;
        }

        $fecha = Carbon::instance($instanteDelEvento)
            ->toImmutable()
            ->subHours($tipo->horasDeAnticipacion());

        if ($existente === null) {
            $nuevo = new Recordatorio;

            /*
             * `setAttribute()` y no `create()`: nada es fillable en este
             * modelo, a propósito -ver su comentario-. Es la misma decisión
             * que `usuario_id` en `CatalogoBaseController`, por el mismo
             * motivo: que exista un solo camino de escritura.
             */
            $nuevo->setAttribute('paciente_id', $paciente->id);
            $nuevo->setAttribute('tipo', $tipo);
            $nuevo->setAttribute('fecha', $fecha);
            $nuevo->setAttribute('origen_type', $origen->getMorphClass());
            $nuevo->setAttribute('origen_id', $origen->getKey());
            $nuevo->setAttribute('estado', EstadoRecordatorio::Pendiente);
            $nuevo->save();

            return;
        }

        /*
         * Comparado al segundo y no con `equalTo()` sobre los objetos: la
         * base guarda segundos, y un microsegundo de diferencia entre el
         * valor recién calculado y el que volvió de una columna `datetime`
         * haría ver como "cambió" algo que no cambió —y reabriría el
         * recordatorio de alguien en cada guardado—.
         */
        if ($existente->fecha->format('Y-m-d H:i:s') === $fecha->format('Y-m-d H:i:s')) {
            return;
        }

        $existente->setAttribute('fecha', $fecha);
        $existente->setAttribute('estado', EstadoRecordatorio::Pendiente);
        $existente->setAttribute('enviado_en', null);
        $existente->setAttribute('fecha_completado', null);
        $existente->save();
    }

    /**
     * Por la clave de idempotencia: `origen_type` + `origen_id` + `tipo`.
     *
     * Las tres columnas están en claro, así que este `where` es un `where`
     * de verdad —no hace falta ningún índice ciego— y lo respalda el UNIQUE
     * de la migración.
     */
    private function buscar(Model&PerteneceAPaciente $origen, TipoRecordatorio $tipo): ?Recordatorio
    {
        return Recordatorio::query()
            ->where('origen_type', $origen->getMorphClass())
            ->where('origen_id', $origen->getKey())
            ->where('tipo', $tipo)
            ->first();
    }
}
