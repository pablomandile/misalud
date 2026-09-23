<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\EsCatalogo;
use App\Http\Requests\CentroGuardarRequest;
use App\Models\Centro;
use App\Models\Medico;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;

/**
 * El catálogo de centros. Copiado de `MedicoController` (ver ese
 * controlador); lo único que suma es sincronizar `medicos` después de
 * guardar, que no puede vivir en `CatalogoBaseController` porque ningún otro
 * catálogo tiene un pivote.
 *
 * @extends CatalogoBaseController<Centro>
 */
class CentroController extends CatalogoBaseController
{
    protected function modelo(): string
    {
        return Centro::class;
    }

    protected function pagina(): string
    {
        return 'catalogos/Centros';
    }

    /**
     * El catálogo de médicos, para armar el checklist de "quién atiende
     * acá". Mismo criterio de visibilidad que `index()` -propios más
     * semillas-, y ordenado en PHP por el mismo motivo de siempre: el
     * nombre está cifrado.
     *
     * @return array<string, mixed>
     */
    protected function propsExtra(): array
    {
        return [
            'medicosDisponibles' => Medico::query()
                ->where($this->visiblesPara(auth()->user()))
                ->get()
                ->sortBy(fn (Medico $medico): string => mb_strtolower($medico->nombre))
                ->values()
                ->map(fn (Medico $medico): array => ['id' => $medico->id, 'nombre' => $medico->nombre])
                ->all(),
        ];
    }

    /**
     * @param  Centro  $registro
     * @return array<string, mixed>
     */
    protected function serializar(Model&EsCatalogo $registro): array
    {
        return [
            'id' => $registro->id,
            'nombre' => $registro->nombre,
            'tipo' => $registro->tipo->value,
            'tipoEtiqueta' => $registro->tipo->etiqueta(),
            'direccion' => $registro->direccion,
            'telefono' => $registro->telefono,
            'notas' => $registro->notas,
            'esSemilla' => $registro->esSemilla(),
            /*
             * Nombres ya en claro y no ids sueltos: la pantalla los muestra
             * tal cual, sin tener que pedir el catálogo de médicos aparte
             * para poder pintarlos.
             */
            'medicos' => $registro->medicos
                ->map(fn (Medico $medico): array => [
                    'id' => $medico->id,
                    'nombre' => $medico->nombre,
                ])
                ->all(),
        ];
    }

    public function store(CentroGuardarRequest $peticion): RedirectResponse
    {
        // `safe($claves)` con claves devuelve un array plano y no un
        // ValidatedInput -a diferencia de `safe()` sin argumentos-, así que
        // acá no hay `->all()` que encadenar.
        $centro = $this->crear($peticion->safe(['nombre', 'tipo', 'direccion', 'telefono', 'notas']));
        $centro->medicos()->sync($peticion->medicosIds());

        return back()->with('exito', "Se agregó a {$centro->nombre}.");
    }

    public function update(CentroGuardarRequest $peticion, Centro $centro): RedirectResponse
    {
        $this->actualizar($centro, $peticion->safe(['nombre', 'tipo', 'direccion', 'telefono', 'notas']));
        $centro->medicos()->sync($peticion->medicosIds());

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(Centro $centro): RedirectResponse
    {
        return $this->eliminar($centro);
    }

    public function duplicar(Centro $centro): RedirectResponse
    {
        /*
         * Duplicar NO copia los médicos vinculados: la copia es de la
         * semilla, con sus propios datos; a quién atiende ahí es algo que
         * cada usuario arma con SUS médicos, no con los de quien publicó la
         * semilla -que además de nadie no tendría médicos vinculados nunca,
         * pero un centro propio duplicado sí podría tenerlos-.
         */
        return $this->duplicarRegistro($centro);
    }
}
