<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RolPaciente;
use App\Http\Controllers\CentroController;
use App\Http\Controllers\FarmaciaController;
use App\Http\Controllers\MedicamentoController;
use App\Http\Controllers\MedicoController;
use App\Http\Controllers\TipoMedicionController;
use App\Http\Controllers\VacunaController;
use App\Models\Centro;
use App\Models\Farmacia;
use App\Models\Medicamento;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\TipoMedicion;
use App\Models\User;
use App\Models\Vacuna;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Lo del catálogo del dueño que ve quien tiene una ficha compartida: los
 * catálogos son de cada cuenta, así que sin esto el cuidador veía la historia
 * con el nombre del médico pero la pantalla Médicos vacía.
 *
 * Dos criterios, decididos por el usuario:
 *
 * - **Médicos, centros y farmacias: la lista COMPLETA del dueño** de la ficha
 *   (`COMPLETOS`). Son la agenda de contactos de la familia, y el límite de
 *   "solo lo usado" dejaba afuera al médico cargado y todavía sin usar.
 *   ⚠️ **Compartir una ficha comparte esa agenda**, incluidos los contactos de
 *   otros familiares del dueño. Es una decisión consciente, y las invitaciones
 *   lo dicen.
 * - **Medicamentos, vacunas y variables: solo lo que usa la ficha.** Sale de
 *   las mismas referencias que muestra la historia, así que no expone nada que
 *   no se vea ya. Dónde se usa cada uno lo declara su controlador en `usos()`
 *   (todas esas tablas cuelgan del paciente por `paciente_id`): una FK nueva
 *   sumada ahí entra sola.
 */
final class CatalogoDeLaFicha
{
    /** @var array<class-string<Model>, class-string> */
    private const CONTROLADORES = [
        Medico::class => MedicoController::class,
        Centro::class => CentroController::class,
        Farmacia::class => FarmaciaController::class,
        Medicamento::class => MedicamentoController::class,
        Vacuna::class => VacunaController::class,
        TipoMedicion::class => TipoMedicionController::class,
    ];

    /**
     * Los catálogos que se comparten enteros con la ficha: la agenda de
     * contactos del dueño.
     *
     * @var list<class-string<Model>>
     */
    public const COMPLETOS = [Medico::class, Centro::class, Farmacia::class];

    /**
     * Los ids del catálogo que ve quien tiene estas fichas: todo lo del dueño
     * (`COMPLETOS`) o lo que la ficha usa. Lo que está en la papelera no
     * cuenta.
     *
     * @param  class-string<Model>  $modelo
     * @param  list<int>  $pacientes
     * @return list<int>
     */
    public static function ids(string $modelo, array $pacientes): array
    {
        if ($pacientes === [] || ! isset(self::CONTROLADORES[$modelo])) {
            return [];
        }

        if (in_array($modelo, self::COMPLETOS, true)) {
            $duenios = DB::table('paciente_usuario')
                ->whereIn('paciente_id', $pacientes)
                ->where('rol', RolPaciente::Propietario->value)
                ->pluck('usuario_id')
                ->all();

            return array_values(array_map('intval', $modelo::query()
                ->whereIn('usuario_id', $duenios)
                ->pluck('id')
                ->all()));
        }

        /** @var list<UsoDeCatalogo> $usos */
        $usos = app(self::CONTROLADORES[$modelo])->usos();

        $ids = [];

        foreach ($usos as $uso) {
            $ids = [...$ids, ...(new $uso->modelo)->newQuery()
                ->whereIn('paciente_id', $pacientes)
                ->whereNotNull($uso->columna)
                ->pluck($uso->columna)
                ->map(fn (mixed $id): int => (int) $id)
                ->all()];
        }

        return array_values(array_unique($ids));
    }

    /**
     * Lo que usan todas las fichas que esta persona puede ver, propias o
     * compartidas.
     *
     * @param  class-string<Model>  $modelo
     * @return list<int>
     */
    public static function idsEnFichasDe(string $modelo, User $usuario): array
    {
        return self::ids($modelo, array_values(array_map(
            'intval',
            $usuario->pacientes()->pluck('pacientes.id')->all(),
        )));
    }

    /**
     * Lo propio, las semillas y lo que usan las fichas que esta persona ve,
     * para un solo `where()`. Agrupado: un OR suelto se mezclaría con cualquier
     * otro filtro (ver `CatalogoVisible`).
     *
     * @param  class-string<Model>  $modelo
     * @return \Closure(Builder): void
     */
    public static function visiblesConFichas(string $modelo, User $usuario): \Closure
    {
        $ids = self::idsEnFichasDe($modelo, $usuario);

        return function ($consulta) use ($usuario, $ids): void {
            $consulta->where(CatalogoVisible::para($usuario->id))->orWhereIn('id', $ids);
        };
    }

    /**
     * Si la solapa de este catálogo muestra la lista completa del dueño o solo
     * lo que usa la ficha: lo necesita el texto de la pantalla.
     *
     * @param  class-string<Model>  $modelo
     */
    public static function esCompleto(string $modelo): bool
    {
        return in_array($modelo, self::COMPLETOS, true);
    }

    /**
     * Las fichas que le compartieron a esta persona (no es la dueña): son las
     * solapas de cada catálogo.
     *
     * @return list<array{id: int, nombre: string}>
     */
    public static function fichasCompartidas(User $usuario): array
    {
        return array_values($usuario->pacientes()
            ->wherePivot('rol', '!=', RolPaciente::Propietario->value)
            ->get()
            ->sortBy(fn (Paciente $paciente): string => mb_strtolower((string) $paciente->nombre))
            ->map(fn (Paciente $paciente): array => ['id' => $paciente->id, 'nombre' => (string) $paciente->nombre])
            ->all());
    }
}
