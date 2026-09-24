<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tratamiento;
use Illuminate\Console\Command;

/**
 * Marca `activo = false` en todo tratamiento activo cuya `fin` ya pasó.
 *
 * Corre por el scheduler (ver `bootstrap/app.php`), una vez al día. Es el
 * primer comando que toca datos según "qué día es hoy", y por eso vale
 * aclarar una decisión que no sigue al pie de la letra la regla de zona
 * horaria del resto de la app:
 *
 * ⚠️ **Compara contra el día de hoy en UTC, no contra el de cada usuario.**
 * `User::hoyCalendario()` existe justamente para esto, pero acá no hay un
 * usuario: es un proceso de fondo que corre una vez para toda la base, y un
 * paciente puede tener varios usuarios con distinta zona (el pivote
 * `paciente_usuario`). Elegir de quién es "el" día sería arbitrario. La
 * imprecisión que queda —hasta unas horas cerca de la medianoche de cada
 * zona— no tiene el costo que tiene en una validación en vivo: ahí un
 * rechazo equivocado le arruina la carga a una persona; acá, en el peor
 * caso, un tratamiento queda marcado activo un día de más y el comando lo
 * corrige solo la corrida siguiente. `activo` es informativo -ayuda a leer
 * la ficha-, no una condición de autorización ni un dato clínico que se
 * pierda.
 *
 * Por lo mismo, **no se declara un enum de estados ni se toca `notas`**: el
 * único cambio es el booleano. Reactivar un tratamiento sigue siendo una
 * acción manual de quien lo edita, no algo que este comando deshaga.
 */
class CerrarTratamientosVencidos extends Command
{
    protected $signature = 'misalud:cerrar-tratamientos-vencidos';

    protected $description = 'Marca como inactivo todo tratamiento activo cuya fecha de fin ya pasó';

    public function handle(): int
    {
        $hoy = now()->toDateString();

        $cerrados = Tratamiento::query()
            ->where('activo', true)
            ->whereNotNull('fin')
            ->whereDate('fin', '<', $hoy)
            ->update(['activo' => false]);

        $this->components->info(
            $cerrados === 0
                ? 'No había tratamientos vencidos para cerrar.'
                : "Se cerraron {$cerrados} tratamiento(s) vencido(s).",
        );

        return self::SUCCESS;
    }
}
