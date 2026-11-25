<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Los mensajes del pedido de medicamentos y sus variables: {nombre}, {apellido} y
 * {medicamento}. Es el único lugar del servidor que las reemplaza.
 *
 * - **Las plantillas se guardan con las variables adentro**, tal cual se
 *   escribieron (la genérica de la cuenta y la propia de cada medicamento): se
 *   reemplazan recién al mandar. Así una plantilla con {medicamento} sigue
 *   sirviendo aunque se cambie el medicamento.
 * - **La ficha guarda el nombre completo en un solo campo**: {nombre} es la
 *   primera palabra y {apellido} el resto. Con un nombre compuesto ("María José
 *   Pérez") el corte no es el que uno esperaría.
 * - La vista previa de la pantalla hace el mismo reemplazo
 *   (`resources/js/lib/plantillaDePedido.ts`); lo que sale en el mail lo decide
 *   este.
 */
final class PlantillaDePedido
{
    /** El de siempre, mientras la cuenta no escriba el suyo. */
    public const ORIGINAL = 'Hola, ¿me podrías hacer la receta de {medicamento} para {nombre} {apellido}? Gracias.';

    /**
     * El genérico de la cuenta, o el original si no escribió ninguno.
     */
    public static function generica(?string $deLaCuenta): string
    {
        $texto = trim((string) $deLaCuenta);

        return $texto === '' ? self::ORIGINAL : $texto;
    }

    /**
     * El nombre completo de la ficha, partido en nombre y apellido.
     *
     * @return array{nombre: string, apellido: string}
     */
    public static function partesDelNombre(string $nombreCompleto): array
    {
        $palabras = preg_split('/\s+/u', trim($nombreCompleto), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [
            'nombre' => $palabras[0] ?? '',
            'apellido' => implode(' ', array_slice($palabras, 1)),
        ];
    }

    /**
     * El mensaje con las variables reemplazadas. Sin importar mayúsculas: {Nombre}
     * vale lo mismo que {nombre}. Una variable que quedó vacía (una ficha sin
     * apellido) no deja un espacio doble.
     */
    public static function completar(string $mensaje, string $medicamento, string $nombreCompleto): string
    {
        $valores = [...self::partesDelNombre($nombreCompleto), 'medicamento' => $medicamento];

        $texto = (string) preg_replace_callback(
            '/\{(nombre|apellido|medicamento)\}/iu',
            fn (array $m): string => $valores[mb_strtolower($m[1])],
            $mensaje,
        );

        // Solo los espacios que dejó una variable vacía: los saltos de línea quedan.
        return (string) preg_replace(['/[ \t]{2,}/u', '/[ \t]+([?!.,;:])/u'], [' ', '$1'], $texto);
    }
}
