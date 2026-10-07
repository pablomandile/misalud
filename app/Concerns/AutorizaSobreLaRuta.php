<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * El `authorize()` de un FormRequest que sirve para crear y para editar.
 *
 * ⚠️ **Autorizar va antes de validar.** Sin `authorize()`, un FormRequest le
 * contesta a cualquiera con los errores de validación —y de paso le confirma que
 * el registro existe— antes de que el controlador llegue a preguntarle a la
 * Policy. Lo encontró el barrido de privacidad (`BarridoDePrivacidadTest`): diez
 * FormRequest le contestaban un 302 con errores a un extraño, en vez de un 403.
 *
 * La regla es una sola, y por eso vive acá: si la ruta trae el registro, hay que
 * poder **editarlo**; si no lo trae, es un alta y hay que poder **crear** uno.
 */
trait AutorizaSobreLaRuta
{
    /**
     * @param  class-string<Model>  $modelo
     */
    protected function puedeGuardar(string $parametro, string $modelo): bool
    {
        $registro = $this->route($parametro);

        return $registro instanceof Model
            ? Gate::allows('update', $registro)
            : Gate::allows('create', $modelo);
    }
}
