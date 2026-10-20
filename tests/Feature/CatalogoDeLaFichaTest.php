<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Models\Centro;
use App\Models\Estudio;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Turno;
use App\Models\User;
use App\Services\LibretaDeDestinatarios;

/*
|--------------------------------------------------------------------------
| Lo del catálogo que usa una ficha compartida (opción B)
|--------------------------------------------------------------------------
|
| El dueño comparte la ficha de su mamá. El cuidador tiene que ver los médicos
| y centros que figuran en esa historia, y NO el resto del catálogo del dueño
| (los médicos de otros familiares).
*/

/**
 * @return array{0: User, 1: User, 2: Paciente, 3: Medico, 4: Medico, 5: Centro}
 */
function fichaCompartidaConCatalogo(): array
{
    $duenio = User::factory()->create();
    $cuidador = User::factory()->create();
    $mama = Paciente::factory()->for($duenio, 'usuario')->create(['nombre' => 'Mamá']);
    $mama->cuidadores()->attach($cuidador->id, ['rol' => RolPaciente::Cuidador->value]);

    $usado = Medico::factory()->for($duenio, 'usuario')->create(['nombre' => 'Dra. Usada', 'email' => 'usada@consultorio.test']);
    $otro = Medico::factory()->for($duenio, 'usuario')->create(['nombre' => 'Dr. De Otro Familiar']);
    $centro = Centro::factory()->for($duenio, 'usuario')->create(['nombre' => 'Clínica de Mamá']);

    Estudio::factory()->for($mama)->create(['medico_id' => $usado->id]);
    Turno::factory()->for($mama)->create(['centro_id' => $centro->id]);

    return [$duenio, $cuidador, $mama, $usado, $otro, $centro];
}

it('ofrece una solapa por cada ficha compartida, no por las propias', function (): void {
    [$duenio, $cuidador, $mama] = fichaCompartidaConCatalogo();
    Paciente::factory()->for($cuidador, 'usuario')->create(['nombre' => 'Propia']);

    $this->actingAs($cuidador)
        ->get(route('medicos.index'))
        ->assertInertia(fn ($p) => $p
            ->where('fichasCompartidas', [['id' => $mama->id, 'nombre' => 'Mamá']])
            ->where('ficha', null));

    $this->actingAs($duenio)
        ->get(route('medicos.index'))
        ->assertInertia(fn ($p) => $p->where('fichasCompartidas', []));
});

it('la solapa de la ficha muestra lo que usa, en solo lectura, y no el resto del catálogo del dueño', function (): void {
    [, $cuidador, $mama] = fichaCompartidaConCatalogo();

    $this->actingAs($cuidador)
        ->get(route('medicos.index', ['ficha' => $mama->id]))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('ficha.id', $mama->id)
            ->has('registros', 1)
            ->where('registros.0.nombre', 'Dra. Usada')
            ->where('registros.0.soloLectura', true));

    $this->actingAs($cuidador)
        ->get(route('centros.index', ['ficha' => $mama->id]))
        ->assertInertia(fn ($p) => $p->has('registros', 1)->where('registros.0.nombre', 'Clínica de Mamá'));
});

it('la lista propia no cambia: el cuidador no ve el catálogo del dueño en «Míos»', function (): void {
    [, $cuidador] = fichaCompartidaConCatalogo();

    $this->actingAs($cuidador)
        ->get(route('medicos.index'))
        ->assertInertia(fn ($p) => $p->has('registros', 0));
});

it('la solapa de una ficha que no le compartieron da 403', function (): void {
    [, , $mama] = fichaCompartidaConCatalogo();

    $this->actingAs(User::factory()->create())
        ->get(route('medicos.index', ['ficha' => $mama->id]))
        ->assertForbidden();
});

it('copia a su lista el médico que usa la ficha, pero no otro del dueño', function (): void {
    [, $cuidador, , $usado, $otro] = fichaCompartidaConCatalogo();

    $this->actingAs($cuidador)
        ->post(route('medicos.duplicar', $usado))
        ->assertSessionHas('exito');

    expect(Medico::where('usuario_id', $cuidador->id)->count())->toBe(1);

    $this->actingAs($cuidador)
        ->post(route('medicos.duplicar', $otro))
        ->assertForbidden();
});

it('no deja editar ni borrar el médico del dueño', function (): void {
    [, $cuidador, , $usado] = fichaCompartidaConCatalogo();

    $this->actingAs($cuidador)->put(route('medicos.update', $usado), ['nombre' => 'X'])->assertForbidden();
    $this->actingAs($cuidador)->delete(route('medicos.destroy', $usado))->assertForbidden();
});

it('Contactos muestra lo que usa la ficha compartida, sin enlace para editar', function (): void {
    [, $cuidador] = fichaCompartidaConCatalogo();

    $this->actingAs($cuidador)
        ->get(route('contactos.index'))
        ->assertInertia(function ($p) {
            $filas = collect($p->toArray()['props']['delCatalogo']);

            expect($filas->pluck('nombre')->all())->toContain('Dra. Usada', 'Clínica de Mamá')
                ->not->toContain('Dr. De Otro Familiar')
                ->and($filas->firstWhere('nombre', 'Dra. Usada')['editar'])->toBeNull();

            return $p;
        });
});

it('el médico de la ficha compartida con correo es un destinatario', function (): void {
    [, $cuidador, , $usado] = fichaCompartidaConCatalogo();

    $libreta = app(LibretaDeDestinatarios::class);

    expect($libreta->resolver($cuidador, "medico:{$usado->id}")?->email)->toBe('usada@consultorio.test');
});
