<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Para qué es un archivo.
 *
 * Es lo único que distingue dos adjuntos colgados del mismo registro: el frente
 * y el dorso de una credencial, o el informe de un estudio y la imagen del
 * estudio en sí.
 *
 * La columna de la base es un `string`, no un ENUM de MySQL: sumar un caso acá
 * no obliga a acordarse de una migración, que es el error que pasa los tests
 * -sqlite no valida ENUM- y revienta en producción al primer guardado.
 */
enum TipoAdjunto: string
{
    /** Frente o dorso de la credencial de la obra social o prepaga. */
    case Credencial = 'credencial';

    /** El papel que da el médico ANTES: la orden para hacerse el estudio. */
    case OrdenEstudio = 'orden_estudio';

    /** El informe del estudio ya hecho. */
    case InformeEstudio = 'informe_estudio';

    /** La imagen o el PDF crudo del estudio (una radiografía, por ejemplo). */
    case ImagenEstudio = 'imagen_estudio';

    /** Receta de medicamentos, cargada a mano o importada del mail. */
    case Receta = 'receta';

    /** El prospecto de un medicamento del catálogo. */
    case Prospecto = 'prospecto';

    /** La receta de anteojos, que se sube aunque los valores estén cargados. */
    case PrescripcionOcular = 'prescripcion_ocular';

    /** Certificado o comprobante de una vacuna. */
    case Vacuna = 'vacuna';

    /**
     * La grabación de una consulta. El ÚNICO tipo que se guarda sin cifrar:
     * ver `seGuardaCifrado()`.
     */
    case AudioConsulta = 'audio_consulta';

    /** Lo que no entra en ninguna de las anteriores. */
    case Otro = 'otro';

    /**
     * Si el contenido del archivo va cifrado en el disco.
     *
     * ⚠️ **El audio no, y es una decisión del usuario** (opción A de la Etapa
     * 16). El cifrado de `ArchivoService` no es *seekable*: obliga a servir el
     * archivo entero, y **iOS Safari no reproduce un `<audio>` sin `Range`**
     * —el botón queda muerto, sin ningún error—. Tampoco se podría adelantar,
     * y una consulta de 40 minutos se descifraría entera en memoria en cada
     * pedido.
     *
     * Lo protege lo mismo que a cualquier adjunto servido por controlador:
     * autorización y un nombre aleatorio. El nombre original y la descripción
     * del adjunto **siguen cifrados** en la base.
     */
    public function seGuardaCifrado(): bool
    {
        return $this !== self::AudioConsulta;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Credencial => 'Credencial',
            self::OrdenEstudio => 'Orden de estudio',
            self::InformeEstudio => 'Informe',
            self::ImagenEstudio => 'Imagen del estudio',
            self::Receta => 'Receta',
            self::Prospecto => 'Prospecto',
            self::PrescripcionOcular => 'Receta de anteojos',
            self::Vacuna => 'Comprobante de vacuna',
            self::AudioConsulta => 'Grabación de la consulta',
            self::Otro => 'Documento',
        };
    }
}
