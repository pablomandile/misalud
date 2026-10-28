<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Centro;
use App\Models\Contacto;
use App\Models\Farmacia;
use App\Models\Medico;
use App\Models\User;
use App\Support\CatalogoDeLaFicha;
use App\Support\Destinatario;
use Illuminate\Support\Collection;

/**
 * A quién se le puede mandar un documento: la libreta de contactos, y además
 * los médicos, centros y farmacias del catálogo que tengan correo (decisión del
 * usuario).
 *
 * ⚠️ **Sigue sin poder tipearse una dirección.** Los dos lados -lo que ofrece
 * la pantalla y lo que acepta la validación- salen de acá: `para()` arma la
 * lista y `resolver()` busca la clave que vino del formulario DENTRO de esa
 * misma lista. Una clave ajena ("medico:7" de otra persona) no está en la
 * lista y no resuelve, como si no existiera.
 *
 * Del catálogo entra lo que esta persona ve: lo propio, las semillas y lo que
 * usan las fichas que le compartieron (`CatalogoDeLaFicha`).
 */
final class LibretaDeDestinatarios
{
    /**
     * @return Collection<int, Destinatario>
     */
    public function para(User $usuario): Collection
    {
        $contactos = $usuario->contactos()->get()->map(fn (Contacto $c): Destinatario => new Destinatario(
            'contacto', $c->id, $c->nombre, $c->email, $c->tipo->etiqueta(),
        ));

        $medicos = Medico::query()->where(CatalogoDeLaFicha::visiblesConFichas(Medico::class, $usuario))->get()
            ->filter(fn (Medico $m): bool => filled($m->email))
            ->map(fn (Medico $m): Destinatario => new Destinatario(
                'medico', $m->id, $m->nombre, (string) $m->email, $m->especialidad ? "Médico · {$m->especialidad}" : 'Médico',
            ));

        // Un centro recibe en su correo de recetas si lo tiene, y si no en el
        // general (`Centro::emailParaEnvios()`).
        $centros = Centro::query()->where(CatalogoDeLaFicha::visiblesConFichas(Centro::class, $usuario))->get()
            ->filter(fn (Centro $c): bool => $c->emailParaEnvios() !== null)
            ->map(fn (Centro $c): Destinatario => new Destinatario(
                'centro', $c->id, $c->nombre, (string) $c->emailParaEnvios(), $c->tipo->etiqueta(),
            ));

        $farmacias = Farmacia::query()->where(CatalogoDeLaFicha::visiblesConFichas(Farmacia::class, $usuario))->get()
            ->filter(fn (Farmacia $f): bool => filled($f->email))
            ->map(fn (Farmacia $f): Destinatario => new Destinatario(
                'farmacia', $f->id, $f->nombre, (string) $f->email, 'Farmacia',
            ));

        // En PHP: los nombres están cifrados.
        return collect()
            ->concat($contactos)
            ->concat($medicos)
            ->concat($centros)
            ->concat($farmacias)
            ->sortBy(fn (Destinatario $d): string => mb_strtolower($d->nombre))
            ->values();
    }

    /**
     * La clave que vino del formulario, buscada entre lo que esta persona puede
     * elegir. `null` si no está: una clave ajena no se distingue de una que no
     * existe.
     */
    public function resolver(User $usuario, string $clave): ?Destinatario
    {
        return $this->para($usuario)->first(fn (Destinatario $d): bool => $d->clave() === $clave);
    }
}
