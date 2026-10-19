<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Paciente;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lo que se sabe del remitente de un mail del buzón (ver
 * `IdentificadorDePaciente`).
 */
final readonly class IdentificacionDeRemitente
{
    /**
     * @param  User|null  $cuenta  la cuenta cuyo mail verificado es el remitente
     * @param  Collection<int, Paciente>  $candidatos  las fichas a las que podría ir
     * @param  Paciente|null  $paciente  la elegida, si se pudo elegir una
     * @param  list<string>  $palabrasDelNombre  las del asunto que nombraron al
     *                                           paciente: no van a la descripción
     */
    public function __construct(
        public ?User $cuenta,
        public Collection $candidatos,
        public ?Paciente $paciente,
        public array $palabrasDelNombre,
    ) {}

    /**
     * Ni una ficha ni una cuenta: se descarta en silencio.
     */
    public function esDesconocido(): bool
    {
        return $this->cuenta === null && $this->candidatos->isEmpty();
    }
}
