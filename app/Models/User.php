<?php

namespace App\Models;

use App\Enums\TamanioTexto;
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
    ];

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
}
