<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * En qué catálogo escribe un FormRequest de catálogo: el de quien es dueño del
 * registro que se edita, o el propio si es un alta.
 *
 * No son lo mismo desde que un cuidador puede editar la lista del dueño de la
 * ficha que cuida (`CatalogoPolicy::update()`). Con `auth()->id()` fijo, el
 * "ya existe uno así" miraba la lista del cuidador: renombrar el centro del
 * dueño a un nombre que el dueño ya tenía pasaba la validación y lo frenaba el
 * UNIQUE de la base con un 500.
 */
trait CatalogoDeLaRuta
{
    protected function duenioDelCatalogo(string $parametro): mixed
    {
        $registro = $this->route($parametro);

        return $registro instanceof Model
            ? $registro->getAttribute('usuario_id')
            : auth()->id();
    }
}
