<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TipoAdjunto;
use App\Models\Adjunto;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Adjunto>
 */
class AdjuntoFactory extends Factory
{
    protected $model = Adjunto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tipo' => TipoAdjunto::Otro,
            'ruta' => 'adjuntos/'.Str::ulid()->toString().'.cif',
            'nombre_original' => 'documento.pdf',
            'descripcion' => null,
            'mime' => 'application/pdf',
            'tamanio_bytes' => 1024,
            'duracion_segundos' => null,
        ];
    }
}
