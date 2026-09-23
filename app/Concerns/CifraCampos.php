<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Índices ciegos para modelos con columnas cifradas.
 *
 * El encrypter de Laravel usa un IV aleatorio: el mismo texto produce un
 * ciphertext distinto cada vez. Eso no hace lenta la búsqueda — la hace
 * imposible. Sobre una columna `encrypted` no se puede poner un UNIQUE, ni un
 * índice, ni un WHERE por igualdad, porque no hay dos filas con el mismo valor
 * almacenado aunque tengan el mismo contenido.
 *
 * La solución es una columna paralela con un HMAC determinístico del valor
 * normalizado. Esa columna sí se indexa y sí se compara.
 *
 * Uso:
 *
 *     class Medico extends Model implements CifraDatos
 *     {
 *         use CifraCampos;
 *
 *         // Obligatorio: rechaza where/orderBy sobre columnas cifradas.
 *         // No puede vivir en el trait — PHP no deja que un trait pise una
 *         // propiedad heredada con otro valor. Lo exige GuardiaDeCifradoTest.
 *         protected static string $builder = ConsultaVigilada::class;
 *
 *         public function indicesCiegos(): array
 *         {
 *             return ['nombre' => 'nombre_hash'];
 *         }
 *
 *         protected function casts(): array
 *         {
 *             return ['nombre' => 'encrypted'];
 *         }
 *     }
 *
 * Los hashes se recalculan solos en `saving`. **No los escribas a mano en un
 * controlador**: si alguien guarda por un camino que no pasa por acá, el índice
 * queda desincronizado del dato y el UNIQUE deja de proteger.
 */
trait CifraCampos
{
    /**
     * Etiqueta de separación de dominio para derivar la clave del índice.
     *
     * La clave del HMAC no es `APP_KEY` directo: se deriva con HKDF. Usar la
     * misma clave para cifrar y para indexar mezcla dos propósitos, y si un día
     * un hash se filtra no queremos que diga nada sobre la clave que descifra
     * la historia clínica.
     */
    private const ETIQUETA_INDICE = 'misalud:indice-ciego:v1';

    public static function bootCifraCampos(): void
    {
        static::saving(static function (Model $modelo): void {
            /** @var static $modelo */
            $modelo->actualizarIndicesCiegos();
        });
    }

    /**
     * Recalcula todas las columnas de hash a partir de su campo de origen.
     *
     * Es pública a propósito: `misalud:recifrar` guarda con `saveQuietly()`
     * para no disparar observers, y eso también saltea el hook de `saving`.
     */
    public function actualizarIndicesCiegos(): void
    {
        foreach ($this->indicesCiegos() as $origen => $columnaHash) {
            $valor = $this->getAttribute($origen);

            $this->setAttribute(
                $columnaHash,
                is_string($valor) ? static::hashCiego($valor) : null,
            );
        }
    }

    /**
     * Acota una consulta por el índice ciego de un campo cifrado.
     *
     * Es el único `where` válido sobre un campo cifrado. Compara **igualdad
     * exacta** del valor normalizado: no hay LIKE, ni "empieza con", ni orden.
     * Para buscar por partes hay que traer las filas del usuario y filtrar en
     * PHP, que en catálogos personales son decenas de registros.
     *
     * @param  Builder<static>  $consulta
     * @return Builder<static>
     */
    public function scopeDondeIndiceCiego(Builder $consulta, string $campo, ?string $valor): Builder
    {
        $columnaHash = $this->indicesCiegos()[$campo] ?? throw new RuntimeException(
            static::class." no declara un índice ciego para [{$campo}]."
        );

        return $consulta->where(
            $columnaHash,
            is_string($valor) ? static::hashCiego($valor) : null,
        );
    }

    /**
     * HMAC determinístico del valor normalizado.
     */
    public static function hashCiego(string $valor): string
    {
        return hash_hmac('sha256', static::normalizarParaIndice($valor), static::claveDeIndice());
    }

    /**
     * Normalización: minúsculas, sin espacios al borde y con los internos
     * colapsados.
     *
     * **Los acentos se conservan.** Sacarlos ayudaría a que "José" y "Jose" se
     * reconozcan como el mismo médico, pero en castellano también haría chocar
     * "Peña" con "Pena", que son dos apellidos distintos. El índice ciego
     * responde "¿es exactamente este?"; la búsqueda tolerante a acentos se hace
     * en PHP sobre las filas ya desencriptadas, que es donde puede ser difusa
     * sin costo.
     */
    protected static function normalizarParaIndice(string $valor): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $valor) ?? $valor));
    }

    /**
     * Clave del HMAC, derivada de APP_KEY por HKDF.
     *
     * Se deriva en cada llamada en vez de cachearse: `hash_hkdf` cuesta
     * microsegundos, y una clave cacheada en una propiedad estática sobreviviría
     * a un cambio de APP_KEY dentro del mismo proceso — que es exactamente lo
     * que hace `misalud:recifrar` y lo que hacen los tests de rotación.
     */
    protected static function claveDeIndice(): string
    {
        $clave = config('app.key');

        if (! is_string($clave) || $clave === '') {
            throw new RuntimeException('APP_KEY no está definida: no se puede calcular el índice ciego.');
        }

        if (str_starts_with($clave, 'base64:')) {
            $clave = base64_decode(substr($clave, 7), true)
                ?: throw new RuntimeException('APP_KEY no es un base64 válido.');
        }

        return hash_hkdf('sha256', $clave, 32, self::ETIQUETA_INDICE);
    }

    /**
     * Mapa campo de origen => columna de hash.
     *
     * @return array<string, string>
     */
    public function indicesCiegos(): array
    {
        return [];
    }

    /**
     * Los campos que este modelo guarda cifrados, según sus casts.
     *
     * Lo usa el test de guardia para verificar que ninguno tenga un índice.
     *
     * @return list<string>
     */
    public function camposCifrados(): array
    {
        return array_keys(array_filter(
            $this->getCasts(),
            static fn (string $cast): bool => $cast === 'encrypted' || str_starts_with($cast, 'encrypted:'),
        ));
    }
}
