<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un lugar donde se usa un registro de catálogo: "los estudios, por su
 * `medico_id`".
 *
 * Cada controlador de catálogo declara los suyos (`usos()`), y
 * `CatalogoBaseController::eliminar()` no deja borrar nada que aparezca en
 * alguno. `ReferenciasACatalogosTest` revisa el esquema real para que ninguna FK
 * hacia un catálogo quede sin declarar acá.
 */
final readonly class UsoDeCatalogo
{
    /**
     * @param  class-string<Model>  $modelo  el registro que apunta al catálogo
     * @param  string  $columna  la FK, en ese registro
     */
    public function __construct(
        public string $singular,
        public string $plural,
        public string $modelo,
        public string $columna,
    ) {}

    /**
     * Cuántos registros lo usan, y cuántos de esos están en la papelera.
     *
     * ⚠️ **La papelera cuenta.** Un estudio borrado se puede restaurar, y
     * volvería sin su médico. Y donde la FK es `restrictOnDelete` (un tratamiento
     * con su medicamento) la base rechazaría el borrado por una fila que nadie ve:
     * sin contarla, borrar daría un 500. Por eso la consulta va sin el scope de
     * soft deletes.
     *
     * @return array{0: int, 1: int}
     */
    public function contar(int $id): array
    {
        $consulta = (new $this->modelo)->newQueryWithoutScopes()->where($this->columna, $id);

        $total = (clone $consulta)->count();

        $enPapelera = in_array(SoftDeletes::class, class_uses_recursive($this->modelo), true)
            ? (clone $consulta)->whereNotNull('deleted_at')->count()
            : 0;

        return [$total, $enPapelera];
    }

    /**
     * La tabla del registro que apunta al catálogo. La usa la guardia del esquema.
     */
    public function tabla(): string
    {
        return (new $this->modelo)->getTable();
    }
}
