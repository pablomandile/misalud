<?php

declare(strict_types=1);

namespace App\Support;

/**
 * En qué terminó una sincronización de una casilla.
 *
 * Los contadores están separados porque cada uno manda a mirar otro lado, y un
 * solo "importadas: 0" no distingue "no llegó nada" de "está todo mal
 * configurado":
 *
 * - muchas **filtradas** y cero importadas: los filtros de remitente no
 *   coinciden con quien manda las recetas;
 * - muchas **sinArchivos**: la carpeta tiene mails, pero no son recetas (o la
 *   farmacia avisa por mail y el PDF hay que bajarlo de su sitio);
 * - muchas **repetidas**: normal, es el solapamiento de la ventana haciendo su
 *   trabajo;
 * - alguna **fallida**: eso sí es un problema, y quedó en el log.
 */
final readonly class ResumenDeSincronizacion
{
    public function __construct(
        public int $miradas = 0,
        public int $importadas = 0,
        public int $repetidas = 0,
        public int $filtradas = 0,
        public int $sinArchivos = 0,
        public int $fallidas = 0,
    ) {}

    /**
     * Lo mismo, pero para un toast y en una sola frase.
     *
     * Dice qué entró y, **solo cuando explica un cero**, por qué no entró nada:
     * un "no había recetas nuevas" a secas sobre una casilla con mails
     * descartados por filtro deja a la persona sin nada que corregir.
     */
    public function paraPantalla(): string
    {
        if ($this->importadas > 0) {
            return $this->importadas === 1
                ? 'Se importó 1 receta nueva.'
                : "Se importaron {$this->importadas} recetas nuevas.";
        }

        if ($this->miradas === 0) {
            return 'No llegó ningún mail nuevo a esa carpeta.';
        }

        if ($this->filtradas > 0 && $this->filtradas === $this->miradas) {
            return 'No se importó nada: todos los mails son de remitentes que no están en tus filtros.';
        }

        if ($this->sinArchivos > 0 && $this->importadas === 0 && $this->repetidas === 0) {
            return 'No se importó nada: los mails de esa carpeta no traen ningún archivo adjunto servible.';
        }

        return 'No había recetas nuevas: ya estaban todas importadas.';
    }

    public function resumen(): string
    {
        return sprintf(
            '%d mirado(s): %d importada(s), %d repetida(s), %d de otro remitente, '
            .'%d sin archivo servible, %d con error.',
            $this->miradas,
            $this->importadas,
            $this->repetidas,
            $this->filtradas,
            $this->sinArchivos,
            $this->fallidas,
        );
    }
}
