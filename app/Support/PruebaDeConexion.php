<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EstadoDeConexion;

/**
 * El resultado de probar una casilla: en qué terminó, y el detalle que solo
 * sirve para ese caso.
 *
 * Existe porque el `detalle` **no puede vivir en el enum**: es un dato del
 * intento y no del caso. Cuando la carpeta no existe, el detalle es la lista
 * de las que sí hay —que es lo único que resuelve el problema del separador—;
 * cuando el fallo es inesperado, es lo que contestó el servidor.
 */
final readonly class PruebaDeConexion
{
    private function __construct(
        public EstadoDeConexion $estado,
        public ?string $detalle = null,
    ) {}

    public static function ok(): self
    {
        return new self(EstadoDeConexion::Ok);
    }

    public static function fallo(EstadoDeConexion $estado, ?string $detalle = null): self
    {
        return new self($estado, $detalle);
    }

    public function anduvo(): bool
    {
        return $this->estado->anduvo();
    }

    /**
     * El mensaje del estado, más el detalle si lo hay.
     */
    public function mensaje(): string
    {
        $mensaje = $this->estado->mensaje();

        return $this->detalle === null ? $mensaje : $mensaje.' '.$this->detalle;
    }
}
