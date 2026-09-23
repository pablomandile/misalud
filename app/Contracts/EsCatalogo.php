<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo implementa todo catálogo del usuario: médicos, centros, medicamentos,
 * vacunas, y los tipos de medición de la Etapa 6.
 *
 * **Un catálogo cuelga del USUARIO, no del paciente.** Es la diferencia que
 * lo separa del resto del dominio clínico y la razón por la que tiene su
 * propia Policy: un médico es el mismo para toda la familia que uno
 * administra, así que la autorización mira `usuario_id` y NO el pivote
 * `paciente_usuario`.
 *
 * `usuario_id` NULL significa **semilla compartida**: la ve todo el mundo,
 * no la edita nadie, y quien la quiera distinta la duplica a su catálogo
 * (regla 5 de CLAUDE.md).
 *
 * **Extiende `CifraDatos` a propósito**, y no por comodidad: el nombre de un
 * catálogo va cifrado en los cinco casos, y con él su índice ciego —que es
 * lo único que permite el UNIQUE por usuario—. Un catálogo que no cifrara su
 * nombre no podría cumplir ni la unicidad ni la búsqueda, así que la
 * herencia dice una verdad del dominio, no una conveniencia del código.
 *
 * `@phpstan-require-extends` dice que todo catálogo ES un modelo de Eloquent,
 * que es cierto en los cinco casos. Sin eso, `CatalogoPolicy` no podría
 * recibir `Model&EsCatalogo` sin que el analizador dude.
 *
 * @phpstan-require-extends Model
 *
 * @property int|null $usuario_id
 */
interface EsCatalogo extends CifraDatos
{
    /**
     * La columna que hace de nombre visible.
     *
     * Casi siempre `nombre`, pero `medicamentos` usa `nombre_comercial`. Se
     * declara acá y no se asume, porque de esto dependen el orden del
     * listado, el índice ciego y el mensaje de "ya existe uno así".
     */
    public function columnaNombre(): string;

    /** El nombre visible ya descifrado. */
    public function nombreVisible(): string;

    /** ¿Es una semilla compartida, de las que no se editan? */
    public function esSemilla(): bool;

    /**
     * El dueño. `null` en las semillas.
     *
     * @return BelongsTo<User, covariant \Illuminate\Database\Eloquent\Model>
     */
    public function usuario(): BelongsTo;
}
