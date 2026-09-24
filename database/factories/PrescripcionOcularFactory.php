<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Ojo;
use App\Enums\TipoPrescripcionOcular;
use App\Models\Paciente;
use App\Models\PrescripcionOcular;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrescripcionOcular>
 */
class PrescripcionOcularFactory extends Factory
{
    protected $model = PrescripcionOcular::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'paciente_id' => Paciente::factory(),
            'medico_id' => null,
            'centro_id' => null,
            'tipo' => $this->faker->randomElement(TipoPrescripcionOcular::cases()),
            'fecha' => now()->subDays($this->faker->numberBetween(0, 900)),
            'dp_total' => null,
            'notas' => null,
        ];
    }

    /**
     * **La factory sostiene el invariante de la etapa**: una receta que sale
     * de acá ya viene con sus dos ojos, igual que una que sale del
     * controlador. Si los creara solo el controlador, cada test tendría que
     * acordarse de armarlos a mano y el primero que se olvidara estaría
     * probando contra una receta que en producción no puede existir.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (PrescripcionOcular $prescripcion): void {
            foreach (Ojo::cases() as $ojo) {
                $prescripcion->graduaciones()->create(
                    GraduacionOcularFactory::new()->datosDelOjo($ojo),
                );
            }
        });
    }

    /**
     * Valores concretos para uno o los dos ojos.
     *
     * **Edita las filas que ya creó `configure()`, no crea otras**: los
     * `afterCreating` corren en orden de registro, así que crear acá chocaría
     * contra el `unique(prescripcion_id, ojo)` —que es justamente el que
     * protege el invariante—.
     *
     * @param  array<string, mixed>  $od
     * @param  array<string, mixed>  $oi
     */
    public function conOjos(array $od = [], array $oi = []): static
    {
        return $this->afterCreating(function (PrescripcionOcular $prescripcion) use ($od, $oi): void {
            if ($od !== []) {
                $prescripcion->graduaciones()->where('ojo', Ojo::Derecho)->first()?->update($od);
            }

            if ($oi !== []) {
                $prescripcion->graduaciones()->where('ojo', Ojo::Izquierdo)->first()?->update($oi);
            }
        });
    }
}
