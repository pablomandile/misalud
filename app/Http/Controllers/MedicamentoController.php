<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\EsCatalogo;
use App\Enums\TipoAdjunto;
use App\Http\Requests\MedicamentoGuardarRequest;
use App\Models\Medicamento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * El catálogo de medicamentos, con su prospecto en PDF.
 *
 * Copiado de `MedicoController`; lo único que suma es serializar el
 * prospecto -un adjunto tipo `Prospecto` colgado del medicamento-. Subirlo y
 * borrarlo no pasa por acá: son las rutas ya genéricas de
 * `AdjuntoController` (`storeParaMedicamento` y `destroy`), la misma que
 * usa una cobertura para su credencial.
 *
 * @extends CatalogoBaseController<Medicamento>
 */
class MedicamentoController extends CatalogoBaseController
{
    protected function modelo(): string
    {
        return Medicamento::class;
    }

    protected function pagina(): string
    {
        return 'catalogos/Medicamentos';
    }

    /** @return array<int, string> */
    protected function conEager(): array
    {
        return ['adjuntos'];
    }

    /**
     * @param  Medicamento  $registro
     * @return array<string, mixed>
     */
    protected function serializar(Model&EsCatalogo $registro): array
    {
        /*
         * Un solo prospecto por medicamento: el más nuevo, si subieron más
         * de uno reemplazando al anterior sin borrarlo antes. No hay URL
         * cacheable especial acá -eso es solo para la credencial-, así que
         * va por `adjuntos.show` como cualquier otro documento.
         */
        $prospecto = $registro->adjuntosDe(TipoAdjunto::Prospecto)
            ->sortByDesc('created_at')
            ->first();

        return [
            'id' => $registro->id,
            'nombre_comercial' => $registro->nombre_comercial,
            'droga' => $registro->droga,
            'para_que_sirve' => $registro->para_que_sirve,
            'notas' => $registro->notas,
            'esSemilla' => $registro->esSemilla(),
            'prospecto' => $prospecto === null ? null : [
                'id' => $prospecto->id,
                'nombre' => $prospecto->nombre_original,
                'mime' => $prospecto->mime,
                'tamanio' => $prospecto->tamanio_bytes,
                'url' => route('adjuntos.show', $prospecto),
            ],
        ];
    }

    public function store(MedicamentoGuardarRequest $peticion): RedirectResponse
    {
        $medicamento = $this->crear($peticion->validated());

        return back()->with('exito', "Se agregó a {$medicamento->nombre_comercial}.");
    }

    public function update(MedicamentoGuardarRequest $peticion, Medicamento $medicamento): RedirectResponse
    {
        $this->actualizar($medicamento, $peticion->validated());

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    /**
     * Borrar un medicamento que ya tiene tratamientos cargados **no se
     * permite**.
     *
     * La FK está en `restrictOnDelete`, así que un borrado real reventaría
     * con un 500; pero acá el `destroy()` de un catálogo es un soft delete,
     * que la base deja pasar sin chistar y dejaría los tratamientos
     * apuntando a un medicamento que ninguna pantalla vuelve a resolver.
     * Mismo freno que ya tiene `TipoMedicionController::destroy()` con las
     * mediciones (ver esa clase).
     */
    public function destroy(Medicamento $medicamento): RedirectResponse
    {
        Gate::authorize('delete', $medicamento);

        if ($medicamento->tratamientos()->exists()) {
            return back()->with(
                'error',
                "No se puede eliminar {$medicamento->nombre_comercial}: ya tiene tratamientos cargados.",
            );
        }

        return $this->eliminar($medicamento);
    }

    public function duplicar(Medicamento $medicamento): RedirectResponse
    {
        // Igual que en centros: duplicar NO copia el prospecto. Es el PDF de
        // quien publicó la semilla, no del catálogo de quien copia.
        return $this->duplicarRegistro($medicamento);
    }
}
