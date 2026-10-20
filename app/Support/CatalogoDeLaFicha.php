<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RolPaciente;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Lo del catálogo que ve quien tiene una ficha compartida: **la lista
 * completa del dueño de esa ficha**, en los seis catálogos (médicos, centros,
 * farmacias, medicamentos, vacunas y variables). Decisión del usuario.
 *
 * Los catálogos son de cada cuenta, así que sin esto el cuidador veía la
 * historia con el nombre del médico y el medicamento pero las pantallas de
 * catálogo vacías. Se probó primero "solo lo que usa la ficha", y dejaba afuera
 * lo cargado y todavía sin usar.
 *
 * ⚠️ **Compartir una ficha comparte esas listas**, incluidos los contactos de
 * otros familiares del dueño. Es una decisión consciente, y el panel de invitar
 * lo avisa. Es la lista del dueño de ESA ficha (el pivote `paciente_usuario`):
 * nunca la de un extraño, y no se mezcla con la propia de quien mira.
 */
final class CatalogoDeLaFicha
{
    /**
     * Los ids del catálogo del dueño de estas fichas.
     *
     * @param  class-string<Model>  $modelo
     * @param  list<int>  $pacientes
     * @return list<int>
     */
    public static function ids(string $modelo, array $pacientes): array
    {
        if ($pacientes === []) {
            return [];
        }

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

    /**
     * El catálogo de los dueños de todas las fichas que esta persona puede ver,
     * propias o compartidas.
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
     * Lo propio, las semillas y lo de los dueños de las fichas que esta persona
     * ve, para un solo `where()`. Agrupado: un OR suelto se mezclaría con
     * cualquier otro filtro (ver `CatalogoVisible`).
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
