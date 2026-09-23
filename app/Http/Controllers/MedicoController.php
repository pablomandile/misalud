<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\EsCatalogo;
use App\Http\Requests\MedicoGuardarRequest;
use App\Models\Medico;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;

/**
 * El catálogo de médicos.
 *
 * **Es la plantilla**: centros, medicamentos y vacunas se escriben igual.
 * Todo lo que se repite vive en `CatalogoBaseController`; acá quedan solo
 * las cuatro cosas que cambian de un catálogo a otro — qué modelo es, qué
 * página lo muestra, cómo se serializa, y los tres métodos que necesitan un
 * type-hint propio (ver el comentario de la clase base).
 *
 * @extends CatalogoBaseController<Medico>
 */
class MedicoController extends CatalogoBaseController
{
    protected function modelo(): string
    {
        return Medico::class;
    }

    protected function pagina(): string
    {
        return 'catalogos/Medicos';
    }

    /**
     * La firma nativa queda en `Model&EsCatalogo` porque PHP no deja
     * angostar un parámetro al implementar (rompería Liskov y es fatal);
     * el `@param` sí lo angosta, y es lo que hace que se vean las columnas
     * de `Medico` sin un solo cast.
     *
     * @param  Medico  $registro
     * @return array<string, mixed>
     */
    protected function serializar(Model&EsCatalogo $registro): array
    {
        return [
            'id' => $registro->id,
            'nombre' => $registro->nombre,
            'especialidad' => $registro->especialidad,
            'telefono' => $registro->telefono,
            'email' => $registro->email,
            'notas' => $registro->notas,
            /*
             * Que sea semilla NO es un detalle de presentación: decide si la
             * pantalla muestra "Editar" o "Copiar a mi catálogo". La Policy
             * lo vuelve a chequear igual del lado del servidor.
             */
            'esSemilla' => $registro->esSemilla(),
        ];
    }

    public function store(MedicoGuardarRequest $peticion): RedirectResponse
    {
        $medico = $this->crear($peticion->validated());

        return back()->with('exito', "Se agregó a {$medico->nombre}.");
    }

    public function update(MedicoGuardarRequest $peticion, Medico $medico): RedirectResponse
    {
        $this->actualizar($medico, $peticion->validated());

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(Medico $medico): RedirectResponse
    {
        return $this->eliminar($medico);
    }

    public function duplicar(Medico $medico): RedirectResponse
    {
        return $this->duplicarRegistro($medico);
    }
}
