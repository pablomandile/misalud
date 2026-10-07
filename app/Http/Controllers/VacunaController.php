<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\EsCatalogo;
use App\Http\Requests\VacunaGuardarRequest;
use App\Models\AplicacionVacuna;
use App\Models\Vacuna;
use App\Support\UsoDeCatalogo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;

/**
 * El catálogo de vacunas. Copiado de `MedicoController` sin ninguna
 * decisión nueva: es el más chico de los cuatro, sin adjuntos propios (ver
 * `Vacuna`).
 *
 * @extends CatalogoBaseController<Vacuna>
 */
class VacunaController extends CatalogoBaseController
{
    protected function modelo(): string
    {
        return Vacuna::class;
    }

    protected function pagina(): string
    {
        return 'catalogos/Vacunas';
    }

    /**
     * @param  Vacuna  $registro
     * @return array<string, mixed>
     */
    protected function serializar(Model&EsCatalogo $registro): array
    {
        return [
            'id' => $registro->id,
            'nombre' => $registro->nombre,
            'notas' => $registro->notas,
            'esSemilla' => $registro->esSemilla(),
        ];
    }

    /**
     * Una vacuna con dosis anotadas no se borra: una dosis "de algo" no se puede
     * leer. `ReferenciasACatalogosTest` falla si aparece otra FK hacia `vacunas`
     * que no figure en esta lista.
     */
    public function usos(): array
    {
        return [
            new UsoDeCatalogo('dosis aplicada', 'dosis aplicadas', AplicacionVacuna::class, 'vacuna_id'),
        ];
    }

    public function store(VacunaGuardarRequest $peticion): RedirectResponse
    {
        $vacuna = $this->crear($peticion->validated());

        return back()->with('exito', "Se agregó {$vacuna->nombre}.");
    }

    public function update(VacunaGuardarRequest $peticion, Vacuna $vacuna): RedirectResponse
    {
        $this->actualizar($vacuna, $peticion->validated());

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(Vacuna $vacuna): RedirectResponse
    {
        return $this->eliminar($vacuna);
    }

    public function duplicar(Vacuna $vacuna): RedirectResponse
    {
        return $this->duplicarRegistro($vacuna);
    }
}
