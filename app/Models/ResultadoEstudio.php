<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Database\Eloquent\ConsultaVigilada;
use App\Policies\RegistroClinicoPolicy;
use Database\Factories\ResultadoEstudioFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un parámetro de un estudio: "Glucemia: 90 mg/dl".
 *
 * **Es el segundo registro clínico que llega a su paciente en DOS pasos**
 * -sube por `estudio` y recién ahí lo encuentra-, el mismo caso que
 * `RegistroEnfermedad` en la Etapa 7. La Policy hay que declararla igual
 * que ahí: el descubrimiento automático de Laravel busca
 * `ResultadoEstudioPolicy` por convención de nombre, y no existe.
 *
 * @property int $id
 * @property int $estudio_id
 * @property string $parametro
 * @property string $valor
 * @property string|null $unidad
 * @property string|null $rango_referencia
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class ResultadoEstudio extends Model implements CifraDatos, PerteneceAPaciente
{
    use CifraCampos;

    /** @use HasFactory<ResultadoEstudioFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'resultados_estudio';

    /*
     * `estudio_id` NO es fillable, por lo mismo que `enfermedad_id` no lo es
     * en `RegistroEnfermedad`: de esa referencia cuelga la autorización
     * entera. Se crea por la relación, `$estudio->resultados()->create(...)`.
     */
    protected $fillable = [
        'parametro',
        'valor',
        'unidad',
        'rango_referencia',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parametro' => 'encrypted',
            'valor' => 'encrypted',
            'unidad' => 'encrypted',
            'rango_referencia' => 'encrypted',
        ];
    }

    public function indicesCiegos(): array
    {
        return ['parametro' => 'parametro_hash'];
    }

    /**
     * @return BelongsTo<Estudio, $this>
     */
    public function estudio(): BelongsTo
    {
        return $this->belongsTo(Estudio::class);
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->estudio?->pacienteDelRegistro();
    }

    /**
     * El número, cuando `valor` lo es.
     *
     * No todo resultado es numérico -"Positivo", "3+"-, así que devuelve
     * `null` en vez de forzar un `(float)` que convertiría cualquier texto
     * en 0.0 sin avisar. Es lo que decide qué entra al gráfico de
     * evolución (paso 9.4): si no convierte, no se grafica.
     */
    public function valorNumerico(): ?float
    {
        return is_numeric($this->valor) ? (float) $this->valor : null;
    }
}
