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

/**
 * Lo del catálogo que usa una ficha: los médicos de sus estudios y turnos, los
 * centros, los medicamentos que toma, las vacunas que se dio.
 *
 * Es lo que ve de una ficha compartida quien no es su dueño (decisión del
 * usuario, "opción B"): los catálogos son de cada cuenta, así que sin esto el
 * cuidador veía la historia con el nombre del médico pero la pantalla Médicos
 * vacía. **No expone nada que no se vea ya en la ficha**: sale de las mismas
 * referencias que muestra la historia. El resto del catálogo del dueño (los
 * médicos de otros familiares) sigue siendo suyo.
 *
 * Dónde se usa cada catálogo ya lo declara su controlador en `usos()`, y todas
 * esas tablas cuelgan del paciente por `paciente_id`: una FK nueva sumada a
 * `usos()` entra acá sola.
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
     * Los ids del catálogo que usan estas fichas. Lo que está en la papelera
     * no cuenta: no se ve en la ficha.
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
