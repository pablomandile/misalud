<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Contracts\PerteneceAPaciente;
use App\Database\Eloquent\ConsultaVigilada;
use App\Policies\RegistroClinicoPolicy;
use Database\Factories\ClaveFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Usuario y contraseña de un sitio de salud del paciente.
 *
 * Cifrada en el servidor como todo lo demás, sin clave maestra: una clave
 * maestra olvidada se llevaría todas las claves, y no dejaría compartirlas con
 * un cuidador. Lo que la protege además del cifrado es que **la clave no viaja
 * nunca en los props**: se pide aparte (`ClaveController::revelar`), con la
 * contraseña de la cuenta reconfirmada.
 *
 * @property int $id
 * @property int $paciente_id
 * @property string $sitio
 * @property string|null $usuario
 * @property string $clave
 * @property string|null $notas
 */
#[UsePolicy(RegistroClinicoPolicy::class)]
class Clave extends Model implements CifraDatos, PerteneceAPaciente
{
    use CifraCampos;

    /** @use HasFactory<ClaveFactory> */
    use HasFactory;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'claves';

    protected $fillable = [
        'sitio',
        'usuario',
        'clave',
        'notas',
    ];

    /**
     * Red por si alguna respuesta futura serializa el modelo entero: la clave
     * sale solo por `revelar`, a propósito.
     *
     * @var list<string>
     */
    protected $hidden = ['clave'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sitio' => 'encrypted',
            'usuario' => 'encrypted',
            'clave' => 'encrypted',
            'notas' => 'encrypted',
        ];
    }

    public function indicesCiegos(): array
    {
        return [];
    }

    /**
     * @return BelongsTo<Paciente, $this>
     */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    public function pacienteDelRegistro(): ?Paciente
    {
        return $this->paciente;
    }
}
