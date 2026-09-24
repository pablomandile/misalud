<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Ojo;
use App\Models\GraduacionOcular;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * **No trae `prescripcion_id`, y es a propósito.** Una graduación suelta no
 * existe: nace siempre de su receta, por la relación —que es además la única
 * forma de escribir esa FK, porque no es fillable—. Se usa así:
 *
 * ```php
 * $prescripcion->graduaciones()->create(GraduacionOcularFactory::new()->datosDelOjo(Ojo::Derecho));
 * ```
 *
 * Los valores por defecto son **consistentes entre sí**: hay cilindro y hay
 * eje. Un default con cilindro y sin eje haría fallar la validación en cada
 * test que reusara estos datos para armar un formulario, y el error se leería
 * como un bug del código y no de la factory.
 *
 * @extends Factory<GraduacionOcular>
 */
class GraduacionOcularFactory extends Factory
{
    protected $model = GraduacionOcular::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ojo' => Ojo::Derecho,
            'esfera' => $this->faker->randomElement(['-1.25', '-0.75', '2.00', '-3.50', '0']),
            'cilindro' => '-0.50',
            'eje' => (string) $this->faker->randomElement([0, 90, 180, 45]),
            'adicion' => null,
            'dp_monocular' => null,
            'prisma' => null,
            'base' => null,
            'agudeza_visual' => null,
        ];
    }

    /**
     * Los valores por defecto de un ojo concreto.
     *
     * Existe en vez de `raw()` porque `raw()` viene declarado como
     * `array<int|string, mixed>` y el `create()` de una relación pide claves
     * de texto: el analizador no puede saber que ninguna clave es un entero.
     *
     * @return array<string, mixed>
     */
    public function datosDelOjo(Ojo $ojo): array
    {
        return ['ojo' => $ojo] + $this->definition();
    }
}
