<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Alguien a quien se le puede mandar un documento: un contacto de la libreta,
 * o un médico, un centro o una farmacia del catálogo con correo cargado.
 *
 * La `clave` ("contacto:12", "medico:3") es lo que viaja en el formulario: dice
 * de qué lista sale y cuál es, y `LibretaDeDestinatarios::resolver()` la vuelve
 * a buscar entre lo que esta persona ve. Nunca viaja una dirección.
 */
final readonly class Destinatario
{
    public function __construct(
        public string $origen,
        public int $id,
        public string $nombre,
        public string $email,
        public string $etiqueta,
    ) {}

    public function clave(): string
    {
        return "{$this->origen}:{$this->id}";
    }

    /** El id del contacto, si sale de la libreta: es lo que guarda el historial. */
    public function contactoId(): ?int
    {
        return $this->origen === 'contacto' ? $this->id : null;
    }

    /**
     * @return array{clave: string, nombre: string, email: string, tipoEtiqueta: string, esDeLaLibreta: bool}
     */
    public function paraPantalla(): array
    {
        return [
            'clave' => $this->clave(),
            'nombre' => $this->nombre,
            'email' => $this->email,
            'tipoEtiqueta' => $this->etiqueta,
            'esDeLaLibreta' => $this->origen === 'contacto',
        ];
    }
}
