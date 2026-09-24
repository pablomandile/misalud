<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\EsCatalogo;
use App\Http\Requests\TipoMedicionGuardarRequest;
use App\Models\TipoMedicion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * El catálogo de variables que se pueden medir. Quinto catálogo, copiado
 * del patrón (ver `MedicoController`).
 *
 * Lo único propio es `destroy()`: es el primer catálogo del que cuelgan
 * registros clínicos, así que borrarlo no puede ser gratis.
 *
 * @extends CatalogoBaseController<TipoMedicion>
 */
class TipoMedicionController extends CatalogoBaseController
{
    protected function modelo(): string
    {
        return TipoMedicion::class;
    }

    protected function pagina(): string
    {
        return 'catalogos/TiposMedicion';
    }

    /**
     * @param  TipoMedicion  $registro
     * @return array<string, mixed>
     */
    protected function serializar(Model&EsCatalogo $registro): array
    {
        return [
            'id' => $registro->id,
            'nombre' => $registro->nombre,
            'unidad' => $registro->unidad,
            'unidad_secundaria' => $registro->unidad_secundaria,
            'etiqueta_principal' => $registro->etiqueta_principal,
            'etiqueta_secundaria' => $registro->etiqueta_secundaria,
            'min_normal' => $registro->min_normal,
            'max_normal' => $registro->max_normal,
            'min_normal_secundario' => $registro->min_normal_secundario,
            'max_normal_secundario' => $registro->max_normal_secundario,
            'decimales' => $registro->decimales,
            'tieneValorSecundario' => $registro->tieneValorSecundario(),
            'esSemilla' => $registro->esSemilla(),
        ];
    }

    public function store(TipoMedicionGuardarRequest $peticion): RedirectResponse
    {
        $tipo = $this->crear($peticion->validated());

        return back()->with('exito', "Se agregó {$tipo->nombre}.");
    }

    public function update(TipoMedicionGuardarRequest $peticion, TipoMedicion $tipo_medicion): RedirectResponse
    {
        $this->actualizar($tipo_medicion, $peticion->validated());

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    /**
     * Borrar un tipo que ya tiene mediciones **no se permite**.
     *
     * La FK está en `restrictOnDelete`, así que un borrado real reventaría
     * con un 500; pero acá el `destroy()` de un catálogo es un soft delete,
     * que la base deja pasar sin chistar y dejaría las mediciones apuntando
     * a un tipo que ninguna pantalla vuelve a resolver. Así que el freno
     * tiene que ser este, con un mensaje que explique la salida real:
     * editar el tipo, que es lo que casi siempre se quería hacer.
     */
    public function destroy(TipoMedicion $tipo_medicion): RedirectResponse
    {
        // Autorizar ANTES de mirar si tiene mediciones: al revés, la
        // respuesta le contaría a un extraño -o a quien intenta borrar una
        // semilla- si esa variable tiene datos cargados o no.
        Gate::authorize('delete', $tipo_medicion);

        if ($tipo_medicion->mediciones()->exists()) {
            return back()->with(
                'error',
                "No se puede eliminar {$tipo_medicion->nombre}: ya tiene mediciones cargadas. Podés editarlo.",
            );
        }

        return $this->eliminar($tipo_medicion);
    }

    public function duplicar(TipoMedicion $tipo_medicion): RedirectResponse
    {
        return $this->duplicarRegistro($tipo_medicion);
    }
}
