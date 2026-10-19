<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RolPaciente;
use App\Models\Paciente;
use App\Models\User;
use App\Support\AsuntoDeDocumento;
use App\Support\IdentificacionDeRemitente;
use Illuminate\Database\Eloquent\Collection;

/**
 * De quién es un mail del buzón: de qué cuenta vino y a qué ficha va.
 *
 * Reconoce dos direcciones, decisión del usuario:
 *
 * - **la del paciente**, el correo cargado en su ficha (por su índice ciego:
 *   `email` está cifrado y un `where` sobre él no encuentra nada nunca);
 * - **la de la cuenta** de quien administra la ficha, solo si ese mail está
 *   VERIFICADO (una dirección sin verificar puede ser el tipeo de un tercero) y
 *   solo las fichas donde esa cuenta puede editar: un lector no carga datos.
 *
 * Con un solo candidato, es ese. Con varios -una cuenta que administra la
 * familia- el asunto tiene que nombrar al paciente («Receta Mamá»): se busca
 * alguna palabra de su nombre entre las del asunto. Si coincide uno solo, es
 * ese; si no, no se adivina.
 */
final class IdentificadorDePaciente
{
    public function identificar(string $remitente, AsuntoDeDocumento $asunto): IdentificacionDeRemitente
    {
        $remitente = mb_strtolower(trim($remitente));

        $porLaFicha = Paciente::query()->dondeIndiceCiego('email', $remitente)->with('cuidadores')->get();

        $cuenta = User::query()
            ->whereRaw('LOWER(email) = ?', [$remitente])
            ->whereNotNull('email_verified_at')
            ->first();

        $porLaCuenta = $cuenta === null
            ? new Collection
            : $cuenta->pacientes()
                ->wherePivotIn('rol', [RolPaciente::Propietario->value, RolPaciente::Cuidador->value])
                ->with('cuidadores')
                ->get();

        /** @var Collection<int, Paciente> $candidatos */
        $candidatos = $porLaFicha->concat($porLaCuenta)->unique('id')->values();

        if ($candidatos->count() === 1) {
            return new IdentificacionDeRemitente($cuenta, $candidatos, $candidatos->first(), []);
        }

        // Varios: el nombre en el asunto decide.
        $delAsunto = $asunto->palabrasNormalizadas();
        $nombrados = [];

        foreach ($candidatos as $candidato) {
            $palabras = $this->palabrasDelNombre($candidato);
            $coinciden = array_values(array_intersect($palabras, $delAsunto));

            if ($coinciden !== []) {
                $nombrados[] = [$candidato, $coinciden];
            }
        }

        return count($nombrados) === 1
            ? new IdentificacionDeRemitente($cuenta, $candidatos, $nombrados[0][0], $nombrados[0][1])
            : new IdentificacionDeRemitente($cuenta, $candidatos, null, []);
    }

    /**
     * Las palabras del nombre que sirven para reconocerlo en un asunto: de tres
     * letras o más, para que un «de» o un «la» no hagan coincidir a cualquiera.
     *
     * @return list<string>
     */
    private function palabrasDelNombre(Paciente $paciente): array
    {
        $palabras = preg_split('/\s+/u', $paciente->nombre) ?: [];

        return array_values(array_filter(
            array_map(AsuntoDeDocumento::normalizar(...), $palabras),
            fn (string $palabra): bool => mb_strlen($palabra) >= 3,
        ));
    }
}
