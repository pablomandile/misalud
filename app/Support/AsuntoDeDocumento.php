<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\TipoDocumentoPorMail;
use Illuminate\Support\Str;

/**
 * Qué dice el asunto de un mail del buzón: el tipo de documento y el resto,
 * que se usa como descripción ("Receta Enalapril 10 mg" → Receta, "Enalapril
 * 10 mg").
 *
 * Sin distinguir mayúsculas ni tildes y con PALABRA ENTERA: «Órdenes», «RECETA»
 * y «estudios» valen; «Recetario» no, ni «Coordenadas». Los prefijos de reenvío
 * («Fwd:», «RV:», «Re:») se saltean: la gente reenvía el mail del médico tal
 * cual, y el asunto llega como «RV: Receta».
 *
 * Si aparecen dos tipos distintos («Receta y orden»), no se adivina: va a
 * revisar. Un mismo tipo repetido no cuenta como dos.
 */
final readonly class AsuntoDeDocumento
{
    private const PALABRAS = [
        'receta' => TipoDocumentoPorMail::Receta,
        'recetas' => TipoDocumentoPorMail::Receta,
        'orden' => TipoDocumentoPorMail::Orden,
        'ordenes' => TipoDocumentoPorMail::Orden,
        'estudio' => TipoDocumentoPorMail::Estudio,
        'estudios' => TipoDocumentoPorMail::Estudio,
    ];

    private const PREFIJOS = ['re', 'rv', 'fw', 'fwd', 'reenviado', 'reenv', 'tr', 'res', 'enc'];

    /**
     * @param  list<string>  $palabras  las palabras del asunto, sin prefijos de reenvío
     */
    private function __construct(
        public ?TipoDocumentoPorMail $tipo,
        public bool $ambiguo,
        private array $palabras,
    ) {}

    public static function leer(?string $asunto): self
    {
        $palabras = self::palabras($asunto ?? '');
        $tipos = [];

        foreach ($palabras as $palabra) {
            $tipo = self::PALABRAS[self::normalizar($palabra)] ?? null;

            if ($tipo !== null) {
                $tipos[$tipo->value] = $tipo;
            }
        }

        return new self(
            tipo: count($tipos) === 1 ? array_values($tipos)[0] : null,
            ambiguo: count($tipos) > 1,
            palabras: $palabras,
        );
    }

    /**
     * Las palabras del asunto, normalizadas (sin tildes, en minúsculas): para
     * buscar el nombre de un paciente sin depender de cómo lo escribió.
     *
     * @return list<string>
     */
    public function palabrasNormalizadas(): array
    {
        return array_values(array_filter(array_map(self::normalizar(...), $this->palabras)));
    }

    /**
     * El asunto sin la palabra del tipo ni las que se le pasen (el nombre del
     * paciente), como lo escribió la persona. Vacío si no queda nada.
     *
     * @param  list<string>  $ademas  palabras a sacar, ya normalizadas
     */
    public function resto(array $ademas = []): string
    {
        $quedan = array_filter(
            $this->palabras,
            fn (string $palabra): bool => ! isset(self::PALABRAS[self::normalizar($palabra)])
                && ! in_array(self::normalizar($palabra), $ademas, true),
        );

        return trim(implode(' ', $quedan), " \t:-–—,.");
    }

    public static function normalizar(string $texto): string
    {
        return mb_strtolower(trim(Str::ascii($texto), " \t:-–—,.;()[]\"'"));
    }

    /**
     * @return list<string>
     */
    private static function palabras(string $asunto): array
    {
        $palabras = preg_split('/\s+/u', trim($asunto)) ?: [];
        $palabras = array_values(array_filter($palabras, fn (string $p): bool => $p !== ''));

        // «RV: Fwd: Receta»: los prefijos de reenvío van al principio, de a uno.
        while ($palabras !== [] && in_array(rtrim(self::normalizar($palabras[0]), ':'), self::PREFIJOS, true)
            && str_ends_with($palabras[0], ':')) {
            array_shift($palabras);
        }

        return $palabras;
    }
}
