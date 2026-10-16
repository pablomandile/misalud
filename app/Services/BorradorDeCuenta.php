<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CifraDatos;
use App\Http\Controllers\CentroController;
use App\Http\Controllers\MedicamentoController;
use App\Http\Controllers\MedicoController;
use App\Http\Controllers\TipoMedicionController;
use App\Http\Controllers\VacunaController;
use App\Models\Centro;
use App\Models\Medicamento;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\TipoMedicion;
use App\Models\User;
use App\Models\Vacuna;
use App\Support\UsoDeCatalogo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Elimina una cuenta DE VERDAD, que es lo que promete la política de privacidad.
 *
 * Antes era un `$user->delete()` a secas, que tenía dos problemas:
 *
 * - **Reventaba con un 500 apenas había un tratamiento cargado.** La base
 *   borraba en cascada la cuenta, sus fichas y su catálogo a la vez, y la FK
 *   `restrictOnDelete` de `tratamientos.medicamento_id` frenaba el borrado del
 *   medicamento antes de que se fuera el tratamiento. Medido contra MySQL. Lo
 *   mismo con una medición y su variable, o una dosis y su vacuna.
 * - **Dejaba los archivos en el disco.** `adjuntos` no tiene FK, así que las
 *   filas quedaban huérfanas y los archivos cifrados (y las grabaciones, que no
 *   lo están) seguían ahí para siempre.
 *
 * El orden importa:
 *
 * 1. Lo del catálogo de esta cuenta que se usa en fichas de OTRAS personas pasa
 *    a esas personas (ver `traspasarCatalogoAjeno`).
 * 2. Las fichas de las que es dueña se borran de verdad, con todo su historial
 *    en cascada. Quienes las compartían dejan de verlas.
 * 3. Las sesiones de la cuenta en cualquier dispositivo.
 * 4. La cuenta, que se lleva en cascada el resto: catálogo, casilla, recetas,
 *    contactos, envíos y su lugar en las fichas compartidas.
 * 5. Recién al final, fuera de la transacción, los archivos que quedaron sin
 *    dueño: si la transacción se deshiciera, no habría que haber tocado el disco.
 */
final readonly class BorradorDeCuenta
{
    /**
     * Cada catálogo y el controlador que declara dónde se usa (`usos()`).
     * `ReferenciasACatalogosTest` cuida que esos usos sean todas las FK reales.
     *
     * @var array<class-string<Model&CifraDatos>, class-string>
     */
    private const CATALOGOS = [
        Medico::class => MedicoController::class,
        Centro::class => CentroController::class,
        Medicamento::class => MedicamentoController::class,
        Vacuna::class => VacunaController::class,
        TipoMedicion::class => TipoMedicionController::class,
    ];

    public function __construct(private BarredorDeArchivosHuerfanos $barredor) {}

    public function borrar(User $usuario): void
    {
        DB::transaction(function () use ($usuario): void {
            foreach (self::CATALOGOS as $modelo => $controlador) {
                $this->traspasarCatalogoAjeno($usuario, $modelo, $controlador);
            }

            Paciente::withTrashed()
                ->where('usuario_id', $usuario->id)
                ->get()
                ->each(fn (Paciente $paciente) => $paciente->forceDelete());

            DB::table('sessions')->where('user_id', $usuario->id)->delete();

            $usuario->delete();
        });

        $this->barredor->barrer();
    }

    /**
     * Un catálogo es de la cuenta, pero un cuidador puede haber usado su propio
     * "Dr. Pérez" en la ficha de otra persona. Borrarlo dejaría ese registro sin
     * médico —o directamente no se podría: un tratamiento no suelta su
     * medicamento—. Así que esa entrada pasa al dueño de la ficha: si ya tiene
     * una con el mismo nombre se usa esa, y si no, se le hace una copia.
     *
     * La copia es solo el registro, sin el prospecto ni los médicos vinculados a
     * un centro: son del catálogo de quien se va.
     *
     * @param  class-string<Model&CifraDatos>  $modelo
     * @param  class-string  $controlador  el que declara los `usos()` del catálogo
     */
    private function traspasarCatalogoAjeno(User $usuario, string $modelo, string $controlador): void
    {
        $propios = (new $modelo)->newQueryWithoutScopes()
            ->where('usuario_id', $usuario->id)
            ->get()
            ->keyBy('id');

        if ($propios->isEmpty()) {
            return;
        }

        /** @var list<UsoDeCatalogo> $usos */
        $usos = app($controlador)->usos();

        foreach ($usos as $uso) {
            $tabla = $uso->tabla();

            // Las filas de fichas AJENAS que apuntan al catálogo de esta cuenta,
            // con la papelera incluida: también se pueden restaurar.
            $filas = DB::table($tabla)
                ->join('pacientes', 'pacientes.id', '=', "{$tabla}.paciente_id")
                ->whereIn("{$tabla}.{$uso->columna}", $propios->keys())
                ->where('pacientes.usuario_id', '!=', $usuario->id)
                ->get(["{$tabla}.id as fila", "{$tabla}.{$uso->columna} as catalogo", 'pacientes.usuario_id as duenio']);

            foreach ($filas->groupBy(fn ($f) => $f->catalogo.'-'.$f->duenio) as $grupo) {
                $primera = $grupo->first();
                $original = $propios->get($primera->catalogo);

                if ($original === null) {
                    continue;
                }

                $destino = $this->entradaDelDuenio($original, (int) $primera->duenio);

                DB::table($tabla)
                    ->whereIn('id', $grupo->pluck('fila'))
                    ->update([$uso->columna => $destino]);
            }
        }
    }

    /**
     * El id de la entrada equivalente en el catálogo del dueño de la ficha:
     * la que ya tiene con ese nombre, o una copia nueva.
     */
    private function entradaDelDuenio(Model&CifraDatos $original, int $duenio): int
    {
        $columnaHash = array_values($original->indicesCiegos())[0];

        $existente = $original->newQueryWithoutScopes()
            ->where('usuario_id', $duenio)
            ->where($columnaHash, $original->getAttribute($columnaHash))
            ->first();

        if ($existente !== null) {
            if (method_exists($existente, 'restore') && $existente->getAttribute('deleted_at') !== null) {
                $existente->restore();
            }

            return (int) $existente->getKey();
        }

        $copia = $original->replicate();
        $copia->setAttribute('usuario_id', $duenio);
        $copia->setAttribute('deleted_at', null);
        $copia->save();

        return (int) $copia->getKey();
    }
}
