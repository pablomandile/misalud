<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\EsCatalogo;
use App\Http\Requests\FarmaciaGuardarRequest;
use App\Models\Farmacia;
use App\Support\UsoDeCatalogo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;

/**
 * El catálogo de farmacias. Copiado del patrón de los catálogos (ver
 * `CatalogoBaseController`) sin decidir nada nuevo.
 *
 * @extends CatalogoBaseController<Farmacia>
 */
class FarmaciaController extends CatalogoBaseController
{
    /**
     * Lo que se guarda del formulario: una sola lista para el alta y la
     * edición (la lección de los centros).
     */
    private const CAMPOS = [
        'nombre', 'direccion', 'telefono', 'whatsapp', 'email', 'web', 'horarios', 'notas',
    ];

    protected function modelo(): string
    {
        return Farmacia::class;
    }

    protected function pagina(): string
    {
        return 'catalogos/Farmacias';
    }

    /**
     * @param  Farmacia  $registro
     * @return array<string, mixed>
     */
    protected function serializar(Model&EsCatalogo $registro): array
    {
        return [
            'id' => $registro->id,
            'nombre' => $registro->nombre,
            'direccion' => $registro->direccion,
            'telefono' => $registro->telefono,
            'whatsapp' => $registro->whatsapp,
            'whatsappEnlace' => $registro->enlaceWhatsapp(),
            'email' => $registro->email,
            'web' => $registro->web,
            'horarios' => $registro->horarios,
            'notas' => $registro->notas,
            'esSemilla' => $registro->esSemilla(),
        ];
    }

    /**
     * Ningún registro de la historia apunta a una farmacia todavía: se borra
     * de verdad siempre. Si algún día una receta guarda dónde se usó, su FK va
     * acá, y `ReferenciasACatalogosTest` lo va a exigir.
     *
     * @return list<UsoDeCatalogo>
     */
    public function usos(): array
    {
        return [];
    }

    public function store(FarmaciaGuardarRequest $peticion): RedirectResponse
    {
        $farmacia = $this->crear($peticion->safe(self::CAMPOS));

        return back()->with('exito', "Se agregó {$farmacia->nombre}.");
    }

    public function update(FarmaciaGuardarRequest $peticion, Farmacia $farmacia): RedirectResponse
    {
        $this->actualizar($farmacia, $peticion->safe(self::CAMPOS));

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(Farmacia $farmacia): RedirectResponse
    {
        return $this->eliminar($farmacia);
    }

    public function duplicar(Farmacia $farmacia): RedirectResponse
    {
        return $this->duplicarRegistro($farmacia);
    }
}
