<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoOrdenEstudio;
use App\Enums\TipoDocumentoPorMail;
use App\Models\Estudio;
use App\Models\OrdenEstudio;
use App\Models\Paciente;
use App\Models\RecetaMedica;
use Carbon\CarbonImmutable;

/**
 * Crea la receta, la orden o el estudio de un documento que llegó por mail.
 *
 * Un solo lugar para el guardado directo del buzón y para "Guardar en…" de lo
 * que quedó por revisar: con dos copias, una receta que entra sola y una que se
 * resuelve a mano terminarían con defaults distintos.
 *
 * Siempre por la relación del paciente: `paciente_id` no es fillable en ningún
 * registro clínico.
 *
 * No es `final` a propósito: los tests del buzón lo reemplazan para simular
 * que un guardado se cae a mitad de camino.
 */
class RegistradorDeDocumento
{
    /**
     * @param  string  $texto  lo que dice el asunto además de la palabra clave
     * @param  CarbonImmutable  $fecha  día de CALENDARIO del documento (en la
     *                                  zona de quien lo recibe, ya resuelta)
     */
    public function crear(
        Paciente $paciente,
        TipoDocumentoPorMail $tipo,
        string $texto,
        CarbonImmutable $fecha,
    ): RecetaMedica|OrdenEstudio|Estudio {
        $texto = trim($texto);
        $dia = $fecha->toDateString();

        return match ($tipo) {
            TipoDocumentoPorMail::Receta => $paciente->recetasMedicas()->create([
                'detalle' => $texto !== '' ? $texto : 'Receta recibida por mail',
                'fecha' => $dia,
                'vigencia_dias' => (int) config('misalud.recetas.vigencia_dias'),
            ]),
            TipoDocumentoPorMail::Orden => $paciente->ordenesEstudio()->create([
                'estudio_solicitado' => $texto !== '' ? $texto : 'Orden recibida por mail',
                'fecha' => $dia,
                'estado' => EstadoOrdenEstudio::Pendiente,
            ]),
            TipoDocumentoPorMail::Estudio => $paciente->estudios()->create([
                'tipo' => $texto !== '' ? $texto : 'Estudio recibido por mail',
                'fecha' => $dia,
            ]),
        };
    }
}
