<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\Ojo;
use App\Policies\RegistroClinicoPolicy;
use Database\Factories\GraduacionOcularFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Los números de UN ojo dentro de una receta.
 *
 * **Es el tercer registro clínico que llega a su paciente en dos pasos**
 * -sube por `prescripcion` y recién ahí lo encuentra-, igual que
 * `RegistroEnfermedad` y `ResultadoEstudio`. Como en esos dos, la Policy hay
 * que declararla a mano: el descubrimiento por convención de Laravel busca
 * `GraduacionOcularPolicy`, que no existe.
 *
 * ## ⚠️ Están cifrados, así que son STRINGS
 *
 * No existe un cast `encrypted:float`. Lo que sale de `$graduacion->esfera`
 * es el texto tal cual se guardó, y eso convierte en trampa cualquier cuenta
 * directa -la misma de `mediciones.valor` y `resultados_estudio.valor`-:
 *
 * ```php
 * $a->esfera > $b->esfera      // compara "-0.75" con "-1.25" como texto
 * (float) $graduacion->eje     // un eje vacío se vuelve 0, que es un eje válido
 * ```
 *
 * El número se pide siempre por `numero()`, que devuelve `null` cuando no lo
 * hay en vez de inventar un cero.
 *
 * @property int $id
 * @property int $prescripcion_id
 * @property Ojo $ojo
 * @property string|null $esfera
 * @property string|null $cilindro
 * @property string|null $eje
 * @property string|null $adicion
 * @property string|null $dp_monocular
 * @property string|null $prisma
 * @property string|null $base
 * @property string|null $agudeza_visual
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class GraduacionOcular extends Model implements CifraDatos, PerteneceAPaciente
{
    use CifraCampos;

    /** @use HasFactory<GraduacionOcularFactory> */
    use HasFactory;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'graduaciones_oculares';

    /** Los campos numéricos, los únicos que `numero()` sabe leer. */
    public const CAMPOS_NUMERICOS = [
        'esfera',
        'cilindro',
        'eje',
        'adicion',
        'dp_monocular',
        'prisma',
    ];

    /*
     * `prescripcion_id` NO es fillable -de esa referencia cuelga la
     * autorización, se pone por la relación-. `ojo` SÍ lo es: no decide
     * ningún permiso, es la otra mitad de la identidad de la fila, y el
     * `updateOrCreate` del controlador la necesita.
     */
    protected $fillable = [
        'ojo',
        'esfera',
        'cilindro',
        'eje',
        'adicion',
        'dp_monocular',
        'prisma',
        'base',
        'agudeza_visual',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ojo' => Ojo::class,
            'esfera' => 'encrypted',
            'cilindro' => 'encrypted',
            'eje' => 'encrypted',
            'adicion' => 'encrypted',
            'dp_monocular' => 'encrypted',
            'prisma' => 'encrypted',
            'base' => 'encrypted',
            'agudeza_visual' => 'encrypted',
        ];
    }

    public function indicesCiegos(): array
    {
        // Ninguno: acá no hay nada que buscar ni que hacer único por texto.
        // El UNIQUE de esta tabla es (prescripcion_id, ojo), las dos en claro.
        return [];
    }

    /**
     * @return BelongsTo<PrescripcionOcular, $this>
     */
    public function prescripcion(): BelongsTo
    {
        return $this->belongsTo(PrescripcionOcular::class, 'prescripcion_id');
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->prescripcion?->pacienteDelRegistro();
    }

    /**
     * El número de un campo, cuando lo hay.
     *
     * Devuelve `null` -y no `0.0`- para lo vacío y para lo que no sea
     * numérico. En un eje eso no es un detalle: `0` **es** un eje válido
     * (horizontal), así que un cero inventado por un cast sería un dato que
     * se lee como si alguien lo hubiera cargado.
     */
    public function numero(string $campo): ?float
    {
        $valor = $this->getAttribute($campo);

        return is_numeric($valor) ? (float) $valor : null;
    }

    /**
     * Una potencia como la escribe una óptica: con signo y dos decimales.
     *
     * Un "+2,00" y un "2" son el mismo número, pero en una receta el segundo
     * se lee como si faltara algo. El signo es obligatorio porque es lo que
     * distingue una miopía de una hipermetropía, y es lo primero que se
     * pierde al copiar a mano.
     *
     * El cero se muestra **sin signo** -"0,00"-: un "+0,00" sugiere una
     * dirección que no existe.
     */
    public function dioptriaVisible(string $campo): ?string
    {
        $numero = $this->numero($campo);

        if ($numero === null) {
            return null;
        }

        $texto = $numero === 0.0
            ? number_format(0, 2, ',', '')
            : sprintf('%+.2f', $numero);

        return str_replace('.', ',', $texto);
    }

    /** El eje es un ángulo entero, sin decimales y con su grado. */
    public function ejeVisible(): ?string
    {
        $eje = $this->numero('eje');

        return $eje === null ? null : ((int) round($eje)).'°';
    }

    /**
     * La línea del ojo tal como la escribe una óptica: esfera, cilindro con
     * su eje, y la adición si la hay.
     *
     * Existe para que se pueda **comparar de un vistazo contra el papel**,
     * que es la única verificación real que esta pantalla admite. Por eso se
     * arma en el servidor con los mismos valores que se guardaron, y no en
     * el navegador a partir de otros: dos armados distintos serían dos
     * formas de leer la misma receta.
     */
    public function resumen(): ?string
    {
        if (! $this->tieneDatos()) {
            return null;
        }

        $cilindro = $this->dioptriaVisible('cilindro');
        $eje = $this->ejeVisible();

        $partes = array_filter([
            $this->dioptriaVisible('esfera'),
            $cilindro !== null && $eje !== null ? "{$cilindro} x {$eje}" : $cilindro,
            ($adicion = $this->dioptriaVisible('adicion')) !== null ? "Add {$adicion}" : null,
        ]);

        return $partes === [] ? null : implode('  ', $partes);
    }

    /**
     * ¿Este ojo tiene algo cargado?
     *
     * Una receta guarda **siempre** sus dos ojos, así que una fila toda en
     * blanco es normal y significa "sin datos" (regla 2), no un error.
     */
    public function tieneDatos(): bool
    {
        foreach ($this->getFillable() as $campo) {
            if ($campo !== 'ojo' && ((string) $this->getAttribute($campo)) !== '') {
                return true;
            }
        }

        return false;
    }
}
