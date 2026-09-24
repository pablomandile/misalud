<?php

namespace App\Models;

use App\Enums\TamanioTexto;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $google_id
 * @property TamanioTexto $tamanio_texto
 * @property string $zona_horaria
 * @property Carbon|null $email_verified_at
 * @property string|null $password Null en las cuentas que entran con Google
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /**
     * Un usuario recién construido ya trae el tamaño por defecto.
     *
     * Sin esto, el default vive solo en la base y un modelo nuevo tiene la
     * propiedad en null hasta recargarlo, que es un null que después aparece
     * lejos de acá.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'tamanio_texto' => TamanioTexto::PORDEFECTO,
        'zona_horaria' => self::ZONA_POR_DEFECTO,
    ];

    /**
     * La zona de quien no eligió ninguna. La app es argentina; esto evita
     * preguntarle la zona horaria a alguien que se acaba de registrar.
     */
    public const ZONA_POR_DEFECTO = 'America/Argentina/Buenos_Aires';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'tamanio_texto' => TamanioTexto::class,
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Todos los pacientes a los que este usuario tiene acceso -propios y
     * compartidos-, con su rol en el pivote.
     *
     * @return BelongsToMany<Paciente, $this>
     */
    public function pacientes(): BelongsToMany
    {
        return $this->belongsToMany(Paciente::class, 'paciente_usuario', 'usuario_id', 'paciente_id')
            ->withPivot('rol')
            ->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | Zona horaria
    |--------------------------------------------------------------------------
    |
    | Se persiste SIEMPRE en UTC y se convierte al mostrar. La conversión vive
    | acá, en un solo lugar, y no desperdigada por los controladores: es el
    | tipo de cuenta que, hecha dos veces distinto, deja dos pantallas
    | mostrando horas diferentes para el mismo registro.
    |
    */

    public function zona(): \DateTimeZone
    {
        /*
         * Una zona inválida -una columna editada a mano, un identificador que
         * la IANA dio de baja- tiraría una excepción y dejaría al usuario sin
         * poder entrar a ninguna pantalla con fechas. Cae al default, que es
         * peor que su zona real pero muchísimo mejor que un 500.
         */
        try {
            return new \DateTimeZone($this->zona_horaria);
        } catch (\Exception) {
            return new \DateTimeZone(self::ZONA_POR_DEFECTO);
        }
    }

    /**
     * Toma lo que escribió la persona —sin zona, como lo manda un
     * `datetime-local`— y lo interpreta EN SU ZONA para devolver el instante
     * en UTC, que es lo que se guarda.
     *
     * Es la mitad que más fácil se olvida: sin esto, "23:30" se guarda como
     * si fuera 23:30 UTC y el registro queda tres horas corrido, sin ningún
     * síntoma hasta que alguien mira la hora.
     */
    public function aUtc(string|\DateTimeInterface|null $valor): ?CarbonImmutable
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return CarbonImmutable::parse($valor, $this->zona())->utc();
    }

    /**
     * La otra mitad: un instante guardado en UTC, mostrado en su zona.
     */
    public function enSuZona(?\DateTimeInterface $valor): ?CarbonImmutable
    {
        if ($valor === null) {
            return null;
        }

        return CarbonImmutable::instance($valor)->setTimezone($this->zona());
    }

    /** Ahora mismo, en la zona de esta persona. */
    public function ahora(): CarbonImmutable
    {
        return CarbonImmutable::now($this->zona());
    }

    /**
     * El comienzo del día de HOY para esta persona, como instante.
     *
     * Para comparar contra columnas **`datetime`**: a las 00:00 del 24 en
     * Buenos Aires le corresponden las 03:00 UTC del 24, y es ese instante el
     * que separa "ayer" de "hoy" entre los registros guardados.
     */
    public function hoy(): CarbonImmutable
    {
        return $this->ahora()->startOfDay();
    }

    /**
     * La FECHA de hoy para esta persona, a medianoche UTC.
     *
     * Para comparar contra columnas **`date`**, que Carbon lee siempre a
     * medianoche UTC. Usar `hoy()` acá corre la comparación tres horas y hace
     * que "mañana" se lea como "hoy" —el error más fácil de cometer con
     * fechas en esta app, y el que no da ningún síntoma—.
     */
    public function hoyCalendario(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            'Y-m-d',
            $this->ahora()->toDateString(),
            'UTC',
        )->startOfDay();
    }
}
