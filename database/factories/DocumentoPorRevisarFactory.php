<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MotivoDeRevision;
use App\Models\DocumentoPorRevisar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sin dueño por defecto: cada test dice de quién es, con `forceFill` o con
 * `for()`, que es lo que hace el procesador del buzón.
 *
 * @extends Factory<DocumentoPorRevisar>
 */
class DocumentoPorRevisarFactory extends Factory
{
    protected $model = DocumentoPorRevisar::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'remitente' => $this->faker->safeEmail(),
            'asunto' => 'Te reenvío esto',
            'motivo' => MotivoDeRevision::SinTipo,
            'tipo_sugerido' => null,
            'fecha_recepcion' => now(),
        ];
    }
}
