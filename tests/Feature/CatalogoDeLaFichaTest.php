<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Models\Centro;
use App\Models\Estudio;
use App\Models\Medicamento;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Tratamiento;
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

it('la solapa de la ficha muestra la lista COMPLETA de médicos del dueño, en solo lectura', function (): void {
    [, $cuidador, $mama] = fichaCompartidaConCatalogo();

    $this->actingAs($cuidador)
        ->get(route('medicos.index', ['ficha' => $mama->id]))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('ficha.id', $mama->id)
            ->where('fichaCompleta', true)
            ->has('registros', 2)
            ->where('registros.0.soloLectura', true)
            ->where('registros.1.soloLectura', true));

    $this->actingAs($cuidador)
        ->get(route('centros.index', ['ficha' => $mama->id]))
        ->assertInertia(fn ($p) => $p->has('registros', 1)->where('registros.0.nombre', 'Clínica de Mamá'));
});

it('no muestra los médicos de OTRO dueño, ni los del cuidador mezclados', function (): void {
    [, $cuidador, $mama] = fichaCompartidaConCatalogo();
    Medico::factory()->create(['nombre' => 'Dr. De Un Extraño']);
    Medico::factory()->for($cuidador, 'usuario')->create(['nombre' => 'Dr. Propio del Cuidador']);

    $this->actingAs($cuidador)
        ->get(route('medicos.index', ['ficha' => $mama->id]))
        ->assertInertia(function ($p) {
            $nombres = collect($p->toArray()['props']['registros'])->pluck('nombre')->all();

            expect($nombres)->not->toContain('Dr. De Un Extraño')
                ->not->toContain('Dr. Propio del Cuidador');

            return $p;
        });
});

it('medicamentos y vacunas siguen mostrando solo lo que usa la ficha', function (): void {
    [$duenio, $cuidador, $mama] = fichaCompartidaConCatalogo();
    Medicamento::factory()->for($duenio, 'usuario')->create(['nombre_comercial' => 'No Usado']);
    $usado = Medicamento::factory()->for($duenio, 'usuario')->create(['nombre_comercial' => 'Usado']);
    Tratamiento::factory()->for($mama)->create(['medicamento_id' => $usado->id]);

    $this->actingAs($cuidador)
        ->get(route('medicamentos.index', ['ficha' => $mama->id]))
        ->assertInertia(fn ($p) => $p
            ->where('fichaCompleta', false)
            ->has('registros', 1)
            ->where('registros.0.nombre_comercial', 'Usado'));
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

it('copia a su lista un médico del dueño, pero no el de un extraño', function (): void {
    [, $cuidador, , $usado, $otro] = fichaCompartidaConCatalogo();
    $extranio = Medico::factory()->create();

    $this->actingAs($cuidador)->post(route('medicos.duplicar', $usado))->assertSessionHas('exito');
    $this->actingAs($cuidador)->post(route('medicos.duplicar', $otro))->assertSessionHas('exito');

    expect(Medico::where('usuario_id', $cuidador->id)->count())->toBe(2);

    $this->actingAs($cuidador)->post(route('medicos.duplicar', $extranio))->assertForbidden();
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
                ->toContain('Dr. De Otro Familiar')
                ->and($filas->firstWhere('nombre', 'Dra. Usada')['editar'])->toBeNull();

            return $p;
        });
});

it('el médico de la ficha compartida con correo es un destinatario', function (): void {
    [, $cuidador, , $usado] = fichaCompartidaConCatalogo();

    $libreta = app(LibretaDeDestinatarios::class);

    expect($libreta->resolver($cuidador, "medico:{$usado->id}")?->email)->toBe('usada@consultorio.test');
});

it('muestra TODOS los médicos y centros que usa la ficha, no solo el primero', function (): void {
    $duenio = User::factory()->create();
    $cuidador = User::factory()->create();
    $mama = Paciente::factory()->for($duenio, 'usuario')->create();
    $mama->cuidadores()->attach($cuidador->id, ['rol' => RolPaciente::Cuidador->value]);

    $medicos = collect([1, 2, 3])->map(fn (int $i) => Medico::factory()->for($duenio, 'usuario')->create(['nombre' => "Médico $i"]));
    $centros = collect([1, 2, 3])->map(fn (int $i) => Centro::factory()->for($duenio, 'usuario')->create(['nombre' => "Centro $i"]));

    Estudio::factory()->for($mama)->create(['medico_id' => $medicos[0]->id, 'centro_id' => $centros[0]->id]);
    Estudio::factory()->for($mama)->create(['medico_id' => $medicos[1]->id, 'centro_id' => $centros[1]->id]);
    Turno::factory()->for($mama)->create(['medico_id' => $medicos[2]->id, 'centro_id' => $centros[2]->id]);

    $this->actingAs($cuidador)
        ->get(route('medicos.index', ['ficha' => $mama->id]))
        ->assertInertia(fn ($p) => $p->has('registros', 3));

    $this->actingAs($cuidador)
        ->get(route('centros.index', ['ficha' => $mama->id]))
        ->assertInertia(fn ($p) => $p->has('registros', 3));
});
