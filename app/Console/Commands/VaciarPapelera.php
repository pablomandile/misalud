<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\TieneArchivos;
use App\Http\Controllers\CentroController;
use App\Http\Controllers\MedicamentoController;
use App\Http\Controllers\MedicoController;
use App\Http\Controllers\TipoMedicionController;
use App\Http\Controllers\VacunaController;
use App\Models\Adjunto;
use App\Models\AplicacionVacuna;
use App\Models\Centro;
use App\Models\Consulta;
use App\Models\Enfermedad;
use App\Models\Estudio;
use App\Models\Medicamento;
use App\Models\Medicion;
use App\Models\Medico;
use App\Models\OrdenEstudio;
use App\Models\Paciente;
use App\Models\PrescripcionOcular;
use App\Models\Receta;
use App\Models\RecetaMedica;
use App\Models\RegistroEnfermedad;
use App\Models\ResultadoEstudio;
use App\Models\TipoMedicion;
use App\Models\Tratamiento;
use App\Models\Turno;
use App\Models\Vacuna;
use App\Services\ArchivoService;
use App\Services\BarredorDeArchivosHuerfanos;
use App\Support\UsoDeCatalogo;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Borra de verdad lo que lleva más de 30 días en la papelera.
 *
 * Borrar algo desde la app (un estudio, una medición, una ficha entera) lo
 * manda a la papelera, por si fue un error. Hasta acá nada la vaciaba: no hay
 * pantalla para restaurar, así que lo borrado quedaba guardado para siempre sin
 * que nadie pudiera verlo. La política de privacidad promete **30 días**, y un
 * test ata ese texto a `DIAS`.
 *
 * - Cada registro se borra con Eloquent (`forceDelete`), no con un DELETE
 *   masivo: así corren los observers —el recordatorio de un turno se va con
 *   él— y la base se lleva en cascada los registros que cuelgan de él.
 * - Los archivos los barre `BarredorDeArchivosHuerfanos` al final: un estudio
 *   borrado de verdad deja sus adjuntos sin dueño, y ahí es donde se van, con
 *   el archivo del disco primero.
 * - **Las recetas son la excepción**: de una receta en la papelera se borra el
 *   PDF, pero la fila queda. Su `message_id_hash` es lo que impide que la
 *   próxima importación la vuelva a traer —para eso se la borró—, y lo que
 *   queda es solo el remitente y el asunto, cifrados.
 * - **Un catálogo** (médico, centro…) de la papelera solo se borra si nada lo
 *   usa. Hoy un catálogo se borra sin pasar por la papelera; esto cubre lo que
 *   haya quedado de antes.
 */
class VaciarPapelera extends Command
{
    /** Lo que promete la política de privacidad. */
    public const DIAS = 30;

    protected $signature = 'misalud:vaciar-papelera {--seco : Mostrar qué se borraría, sin borrar nada}';

    protected $description = 'Borra de verdad lo que lleva más de 30 días en la papelera, con sus archivos';

    /**
     * Los registros clínicos con papelera. El orden no importa para la base
     * —cada uno se lleva lo suyo en cascada—, pero las fichas van al final para
     * que los observers de lo que cuelga de ellas corran con la ficha todavía ahí.
     *
     * @var list<class-string<Model>>
     */
    private const CLINICOS = [
        Medicion::class,
        RegistroEnfermedad::class,
        Enfermedad::class,
        Tratamiento::class,
        AplicacionVacuna::class,
        ResultadoEstudio::class,
        Estudio::class,
        OrdenEstudio::class,
        RecetaMedica::class,
        PrescripcionOcular::class,
        Consulta::class,
        Turno::class,
        Paciente::class,
    ];

    /**
     * @var array<class-string<Model>, class-string>
     */
    private const CATALOGOS = [
        Medico::class => MedicoController::class,
        Centro::class => CentroController::class,
        Medicamento::class => MedicamentoController::class,
        Vacuna::class => VacunaController::class,
        TipoMedicion::class => TipoMedicionController::class,
    ];

    public function handle(ArchivoService $archivos, BarredorDeArchivosHuerfanos $barredor): int
    {
        $limite = now()->subDays(self::DIAS);
        $seco = (bool) $this->option('seco');

        foreach (self::CLINICOS as $modelo) {
            $vencidos = $this->vencidos($modelo, $limite);
            $this->line(sprintf('  %-22s %d', class_basename($modelo), $vencidos->count()));

            if (! $seco) {
                $vencidos->each(fn (Model $registro) => $registro->forceDelete());
            }
        }

        foreach (self::CATALOGOS as $modelo => $controlador) {
            /** @var list<UsoDeCatalogo> $usos */
            $usos = app($controlador)->usos();

            $sinUso = $this->vencidos($modelo, $limite)->filter(function (Model $registro) use ($usos): bool {
                foreach ($usos as $uso) {
                    if ($uso->contar((int) $registro->getKey())[0] > 0) {
                        return false;
                    }
                }

                return true;
            });

            $this->line(sprintf('  %-22s %d', class_basename($modelo), $sinUso->count()));

            if (! $seco) {
                $sinUso->each(function (Model $registro) use ($archivos): void {
                    if ($registro instanceof TieneArchivos) {
                        $archivos->borrarTodosDe($registro);
                    }

                    $registro->forceDelete();
                });
            }
        }

        // Las recetas: se va el PDF, queda la huella que evita reimportarlas.
        $recetas = $this->vencidos(Receta::class, $limite);
        $this->line(sprintf('  %-22s %d (solo el archivo)', 'Receta', $recetas->count()));

        if (! $seco) {
            $recetas->each(fn (Receta $receta) => $archivos->borrarTodosDe($receta));
        }

        // Los adjuntos de la papelera: su archivo ya se borró al mandarlos ahí,
        // queda la fila. `borrar()` no explota si el archivo ya no está.
        $adjuntos = $this->vencidos(Adjunto::class, $limite);
        $this->line(sprintf('  %-22s %d', 'Adjunto', $adjuntos->count()));

        if (! $seco) {
            $adjuntos->each(function (Adjunto $adjunto) use ($archivos): void {
                $archivos->borrar($adjunto);
                $adjunto->forceDelete();
            });

            $huerfanos = $barredor->barrer();
            $this->info("Listo. Archivos sin dueño borrados: {$huerfanos}.");
        } else {
            $this->comment('Ensayo en seco: no se borró nada.');
        }

        return self::SUCCESS;
    }

    /**
     * Lo que está en la papelera desde antes del límite.
     *
     * @template TModelo of Model
     *
     * @param  class-string<TModelo>  $modelo
     * @return Collection<int, TModelo>
     */
    private function vencidos(string $modelo, \DateTimeInterface $limite): Collection
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($modelo), true)) {
            return collect();
        }

        /** @phpstan-ignore staticMethod.notFound (onlyTrashed lo aporta SoftDeletes, verificado arriba) */
        return $modelo::onlyTrashed()->where('deleted_at', '<=', $limite)->get();
    }
}
