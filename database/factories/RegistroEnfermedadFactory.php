<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enfermedad;
use App\Models\RegistroEnfermedad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistroEnfermedad>
 */
class RegistroEnfermedadFactory extends Factory
{
    protected $model = RegistroEnfermedad::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enfermedad_id' => Enfermedad::factory(),
            'fecha' => now()->subDays($this->faker->numberBetween(0, 90)),
            'nota' => $this->faker->sentence(),
        ];
    }
}
