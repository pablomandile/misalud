<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\CifraDatos;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reescribe todo el contenido cifrado con la APP_KEY actual.
 *
 * Es lo que permite rotar APP_KEY sin perder los datos. El procedimiento es:
 *
 *   1. Copiar la APP_KEY actual a APP_PREVIOUS_KEYS en el .env.
 *   2. `php artisan key:generate` (escribe una APP_KEY nueva).
 *   3. `php artisan misalud:recifrar`
 *   4. Recién ahí, sacar la clave vieja de APP_PREVIOUS_KEYS.
 *
 * Entre el paso 2 y el 3 la app **sigue funcionando**: el encrypter de Laravel
 * prueba las claves previas al descifrar. Lo que no funciona en ese intervalo
 * son los índices ciegos, que se calculan con la clave nueva y ya no coinciden
 * con los guardados — por eso el comando también los recalcula, y por eso
 * conviene correrlo enseguida.
 */
class Recifrar extends Command
{
    protected $signature = 'misalud:recifrar
        {--modelo=* : Limitar a estas clases de modelo (por defecto, todas las de app/Models)}
        {--seco : Informar qué se haría, sin escribir nada}';

    protected $description = 'Reescribe el contenido cifrado y los índices ciegos con la APP_KEY actual';

    public function handle(): int
    {
        $seco = (bool) $this->option('seco');

        $modelos = $this->modelosACifrar();

        if ($modelos === []) {
            $this->components->warn('Ningún modelo implementa CifraDatos. No hay nada que hacer.');

            return self::SUCCESS;
        }

        if ($seco) {
            $this->components->info('Modo seco: no se escribe nada.');
        }

        $fallados = 0;

        foreach ($modelos as $clase) {
            $fallados += $this->recifrarModelo($clase, $seco);
        }

        if ($fallados > 0) {
            $this->components->error(
                "{$fallados} fila(s) no se pudieron descifrar. Revisá que la clave vieja esté en "
                .'APP_PREVIOUS_KEYS antes de sacarla del .env.'
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model&CifraDatos>  $clase
     * @return int cantidad de filas que no se pudieron descifrar
     */
    private function recifrarModelo(string $clase, bool $seco): int
    {
        $modelo = new $clase;
        $tabla = $modelo->getTable();
        $llave = $modelo->getKeyName();

        /** @var list<string> $campos */
        $campos = $modelo->camposCifrados();
        $indices = $modelo->indicesCiegos();

        if ($campos === [] && $indices === []) {
            return 0;
        }

        $hechas = 0;
        $fallados = 0;

        $clase::query()->chunkById(200, function ($filas) use (
            $clase, $tabla, $llave, $campos, $indices, $seco, &$hechas, &$fallados
        ): void {
            foreach ($filas as $fila) {
                try {
                    $valores = $this->valoresRecifrados($fila, $campos, $indices);
                } catch (Throwable $e) {
                    $fallados++;
                    $this->components->warn(
                        "{$clase} #{$fila->getKey()}: no se pudo descifrar ({$e->getMessage()})"
                    );

                    continue;
                }

                if ($valores === []) {
                    continue;
                }

                if (! $seco) {
                    /*
                     * Se escribe por el query builder y no con save(): un save()
                     * dispararía los observers (que generan recordatorios) por
                     * un cambio que no es del dominio, y además el dirty-check
                     * de Eloquent compara los valores *descifrados*, así que un
                     * texto que no cambió no se marcaría sucio y no se
                     * reescribiría nunca.
                     */
                    DB::table($tabla)->where($llave, $fila->getKey())->update($valores);
                }

                $hechas++;
            }
        });

        $verbo = $seco ? 'se recifrarían' : 'recifradas';
        $this->components->twoColumnDetail(class_basename($clase), "{$hechas} fila(s) {$verbo}");

        return $fallados;
    }

    /**
     * Los valores ya cifrados con la clave actual, más los índices recalculados.
     *
     * El cifrado no se hace a mano: se le pasan los valores en claro a una
     * instancia nueva del modelo y se leen sus atributos crudos. Así vale para
     * `encrypted`, `encrypted:array`, `encrypted:json` y cualquier variante que
     * Laravel agregue, sin que este comando tenga que conocerlas.
     *
     * @param  list<string>  $campos
     * @param  array<string, string>  $indices
     * @return array<string, mixed>
     */
    private function valoresRecifrados(Model&CifraDatos $fila, array $campos, array $indices): array
    {
        $copia = $fila->newInstance();

        foreach ($campos as $campo) {
            // Leer descifra con la clave actual o con alguna de APP_PREVIOUS_KEYS.
            $copia->setAttribute($campo, $fila->getAttribute($campo));
        }

        $valores = array_intersect_key($copia->getAttributes(), array_flip($campos));

        if ($indices !== []) {
            $copia->actualizarIndicesCiegos();
            $valores += array_intersect_key($copia->getAttributes(), array_flip(array_values($indices)));
        }

        return $valores;
    }

    /**
     * @return list<class-string<Model&CifraDatos>>
     */
    private function modelosACifrar(): array
    {
        /** @var list<string> $pedidos */
        $pedidos = $this->option('modelo');

        $candidatos = $pedidos !== []
            ? $pedidos
            : array_map(
                static fn (string $ruta): string => 'App\\Models\\'.Str::before(basename($ruta), '.php'),
                glob(app_path('Models/*.php')) ?: [],
            );

        $usados = [];

        foreach ($candidatos as $clase) {
            if (! class_exists($clase) || ! is_subclass_of($clase, Model::class)) {
                $this->components->warn("Se ignora [{$clase}]: no es un modelo Eloquent.");

                continue;
            }

            if (is_a($clase, CifraDatos::class, true)) {
                $usados[] = $clase;
            }
        }

        return $usados;
    }
}
