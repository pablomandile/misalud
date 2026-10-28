<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\CatalogosSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Vacía la base y vuelve a sembrar la demo. Corre todas las noches en la copia
 * de pablomandile (ver `bootstrap/app.php`).
 *
 * ⚠️ Borra **todo** el disco privado, no carpetas sueltas: ahí van los archivos
 * cifrados y los audios en claro que suben los visitantes, en una carpeta por
 * tabla. Una lista de carpetas dejaría afuera la del próximo dueño de archivos.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Borra todos los datos y resiembra la familia de la demo (solo con APP_DEMO=true)';

    public function handle(): int
    {
        // Sin el flag no se toca nada: este comando borra la base entera.
        if (! config('demo.enabled')) {
            $this->error('La demo no está activa (APP_DEMO). No se borró nada.');

            return self::FAILURE;
        }

        // En producción `AppServiceProvider` prohíbe `migrate:fresh`, y la demo
        // corre con APP_ENV=production. Se levanta solo acá, que ya exige el flag.
        DB::prohibitDestructiveCommands(false);

        // Si la base no se vació, no se sigue: sembrar encima mezclaría la demo
        // con lo que hubiera, y borrar los archivos dejaría filas sin su archivo.
        if ($this->call('migrate:fresh', ['--force' => true]) !== self::SUCCESS) {
            $this->error('No se pudo vaciar la base. No se tocó nada más.');

            return self::FAILURE;
        }

        $disco = Storage::disk('local');
        foreach ($disco->directories() as $carpeta) {
            $disco->deleteDirectory($carpeta);
        }
        $disco->delete($disco->files());

        // Lo que los visitantes hicieron durante el día no tiene por qué quedar.
        $log = storage_path('logs/laravel.log');
        if (File::exists($log)) {
            File::put($log, '');
        }

        $this->call('db:seed', ['--class' => CatalogosSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        return self::SUCCESS;
    }
}
