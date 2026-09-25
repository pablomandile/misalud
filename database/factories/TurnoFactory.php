<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoTurno;
use App\Models\Paciente;
use App\Models\Turno;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Por defecto un turno **futuro y programado**, que es el caso normal: un
 * turno es algo que se agenda. Los tests que necesiten uno pasado o
 * cancelado lo piden con sus estados.
 *
 * @extends Factory<Turno>
 */
class TurnoFactory extends Factory
{
    protected $model = Turno::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'paciente_id' => Paciente::factory(),
            'medico_id' => null,
            'centro_id' => null,
            'orden_estudio_id' => null,
            'fecha_hora' => now()->addDays($this->faker->numberBetween(2, 60))->setTime(10, 0),
            'motivo' => $this->faker->randomElement([
                'Control anual',
                'Consulta por dolor de rodilla',
                'Revisión de análisis',
            ]),
            'estado' => EstadoTurno::Programado,
        ];
    }

    /** Ya fue. */
    public function asistido(): static
    {
        return $this->state(fn (): array => [
            'estado' => EstadoTurno::Asistido,
            'fecha_hora' => now()->subDays($this->faker->numberBetween(1, 90))->setTime(10, 0),
        ]);
    }

    /** Se dio de baja: **no lleva recordatorio** (ver `TurnoObserver`). */
    public function cancelado(): static
    {
        return $this->state(fn (): array => ['estado' => EstadoTurno::Cancelado]);
    }
}
