<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CifraCampos;
use App\Contracts\CifraDatos;
use App\Database\Eloquent\ConsultaVigilada;
use App\Enums\ResultadoDeMail;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Qué pasó con un mail del buzón: la deduplicación (`message_id_hash`) y el
 * registro. Lo escribe solo `ProcesadorDeBuzon`; no hay ninguna ruta que lo
 * toque, y por eso no tiene Policy ni factory.
 *
 * De un remitente desconocido se guarda únicamente el hash del mensaje: ni su
 * dirección ni el asunto.
 *
 * @property int $id
 * @property string $message_id_hash
 * @property string|null $remitente
 * @property string|null $remitente_hash
 * @property string|null $asunto
 * @property ResultadoDeMail $resultado
 * @property int|null $paciente_id
 * @property CarbonImmutable $recibido_en
 */
class MailRecibido extends Model implements CifraDatos
{
    use CifraCampos;

    /** @var class-string<\Illuminate\Database\Eloquent\Builder<*>> */
    protected static string $builder = ConsultaVigilada::class;

    protected $table = 'mails_recibidos';

    protected $fillable = [
        'message_id_hash',
        'remitente',
        'asunto',
        'resultado',
        'paciente_id',
        'recibido_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'remitente' => 'encrypted',
            'asunto' => 'encrypted',
            'resultado' => ResultadoDeMail::class,
            'recibido_en' => 'immutable_datetime',
        ];
    }

    /**
     * El remitente, para contar cuántos mails mandó hoy (el tope por día) sin
     * descifrar cada fila.
     */
    public function indicesCiegos(): array
    {
        return ['remitente' => 'remitente_hash'];
    }
}
