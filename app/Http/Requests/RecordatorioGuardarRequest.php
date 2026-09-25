<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Recordatorio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Lo único que una persona puede cambiarle a un recordatorio: si está hecho
 * o no.
 *
 * No hay un FormRequest de alta porque **no hay alta**: los recordatorios los
 * genera `GeneradorDeRecordatorios` desde un observer, y no existe ninguna
 * ruta que los cree ni que los borre.
 */
class RecordatorioGuardarRequest extends FormRequest
{
    public function authorize(): bool
    {
        $recordatorio = $this->route('recordatorio');

        /*
         * `update` y no un permiso propio: marcar un aviso como hecho es
         * tocar la ficha, así que un `Lector` no puede -misma regla que los
         * adjuntos con su dueño-.
         */
        return $recordatorio instanceof Recordatorio
            && Gate::allows('update', $recordatorio);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /*
         * Un booleano y no el estado completo: los otros tres estados los
         * escribe el sistema (`Pendiente` el observer, `Enviado` y `Vencido`
         * el comando horario). Dejar que el formulario mande un `estado`
         * arbitrario permitiría marcar algo como "ya avisado" sin que se
         * haya mandado ningún mail.
         */
        return ['completado' => ['required', 'boolean']];
    }

    protected function prepareForValidation(): void
    {
        // ⚠️ Un checkbox tildado manda el string "on", que la regla `boolean`
        // rechaza. Ver la regla en CLAUDE.md, sección Backend.
        $this->merge(['completado' => $this->boolean('completado')]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['completado' => 'estado del recordatorio'];
    }
}
