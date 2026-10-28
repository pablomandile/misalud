<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Models\Centro;
use App\Models\Estudio;
use App\Models\Medicamento;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\TipoMedicion;
use App\Models\Turno;
use App\Models\User;
use App\Models\Vacuna;
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

it('la solapa de la ficha muestra la lista COMPLETA de médicos del dueño: el cuidador la edita, no la borra', function (): void {
    [, $cuidador, $mama] = fichaCompartidaConCatalogo();

    $this->actingAs($cuidador)
        ->get(route('medicos.index', ['ficha' => $mama->id]))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('ficha.id', $mama->id)
            ->has('registros', 2)
            ->where('registros.0.soloLectura', false)
            ->where('registros.0.propio', false)
            ->where('registros.1.soloLectura', false));

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

it('medicamentos, vacunas y variables también muestran la lista completa del dueño', function (): void {
    [$duenio, $cuidador, $mama] = fichaCompartidaConCatalogo();
    Medicamento::factory()->for($duenio, 'usuario')->count(2)->create();
    Vacuna::factory()->for($duenio, 'usuario')->create();
    TipoMedicion::factory()->for($duenio, 'usuario')->create();
    Medicamento::factory()->for($cuidador, 'usuario')->create();

    $this->actingAs($cuidador)
        ->get(route('medicamentos.index', ['ficha' => $mama->id]))
        ->assertInertia(fn ($p) => $p->has('registros', 2)->where('registros.0.soloLectura', false));

    $this->actingAs($cuidador)
        ->get(route('vacunas.index', ['ficha' => $mama->id]))
        ->assertInertia(fn ($p) => $p->has('registros', 1));

    $this->actingAs($cuidador)
        ->get(route('tipos-medicion.index', ['ficha' => $mama->id]))
        ->assertInertia(fn ($p) => $p->has('registros', 1));
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

it('a un lector la lista del dueño le queda en solo lectura', function (): void {
    [$duenio, , $mama] = fichaCompartidaConCatalogo();
    $lector = User::factory()->create();
    $mama->cuidadores()->attach($lector->id, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->get(route('medicos.index', ['ficha' => $mama->id]))
        ->assertInertia(fn ($p) => $p->has('registros', 2)->where('registros.0.soloLectura', true));
});

it('el cuidador edita el médico del dueño, pero no lo borra', function (): void {
    [, $cuidador, , $usado, $otro] = fichaCompartidaConCatalogo();

    $this->actingAs($cuidador)
        ->put(route('medicos.update', $usado), ['nombre' => 'Dra. Usada Corregida'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($usado->fresh()->nombre)->toBe('Dra. Usada Corregida');

    // Sin uso, para que lo que frene sea la Policy y no los usos.
    $this->actingAs($cuidador)->delete(route('medicos.destroy', $otro))->assertForbidden();
    expect($otro->fresh())->not->toBeNull();
});

it('un lector o un extraño no editan el médico del dueño', function (): void {
    [, , $mama, $usado] = fichaCompartidaConCatalogo();
    $lector = User::factory()->create();
    $mama->cuidadores()->attach($lector->id, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)->put(route('medicos.update', $usado), ['nombre' => 'X'])->assertForbidden();
    $this->actingAs(User::factory()->create())->put(route('medicos.update', $usado), ['nombre' => 'X'])->assertForbidden();
});

it('al editar lo del dueño, «ya existe uno así» mira la lista del dueño', function (): void {
    [, $cuidador, , $usado] = fichaCompartidaConCatalogo();
    Medico::factory()->for($cuidador, 'usuario')->create(['nombre' => 'Dr. Del Cuidador']);

    // El dueño ya tiene a "Dr. De Otro Familiar": el UNIQUE de la base lo
    // frenaría con un 500 si la validación mirara la lista del cuidador.
    $this->actingAs($cuidador)
        ->put(route('medicos.update', $usado), ['nombre' => 'Dr. De Otro Familiar'])
        ->assertSessionHasErrors('nombre');

    // Un nombre que solo tiene el cuidador no choca con nada del dueño.
    $this->actingAs($cuidador)
        ->put(route('medicos.update', $usado), ['nombre' => 'Dr. Del Cuidador'])
        ->assertSessionHasNoErrors();
});

it('al editar el centro del dueño, solo se le vinculan médicos del dueño', function (): void {
    [, $cuidador, , $usado, , $centro] = fichaCompartidaConCatalogo();
    $delCuidador = Medico::factory()->for($cuidador, 'usuario')->create();

    $datos = ['nombre' => 'Clínica de Mamá', 'tipo' => $centro->tipo->value];

    $this->actingAs($cuidador)
        ->put(route('centros.update', $centro), [...$datos, 'medicos' => [$delCuidador->id]])
        ->assertSessionHasErrors('medicos.0');

    $this->actingAs($cuidador)
        ->put(route('centros.update', $centro), [...$datos, 'medicos' => [$usado->id]])
        ->assertSessionHasNoErrors();

    expect($centro->medicos()->pluck('medicos.id')->all())->toBe([$usado->id]);
});

it('la pantalla de centros ofrece los médicos de cada catálogo con su dueño', function (): void {
    [$duenio, $cuidador, , $usado] = fichaCompartidaConCatalogo();
    $delCuidador = Medico::factory()->for($cuidador, 'usuario')->create();
    $extranio = Medico::factory()->create();

    $this->actingAs($cuidador)
        ->get(route('centros.index'))
        ->assertInertia(function ($p) use ($usado, $delCuidador, $extranio, $duenio, $cuidador) {
            $medicos = collect($p->toArray()['props']['medicosDisponibles'])->keyBy('id');

            expect($medicos->get($usado->id)['usuarioId'])->toBe($duenio->id)
                ->and($medicos->get($delCuidador->id)['usuarioId'])->toBe($cuidador->id)
                ->and($medicos->has($extranio->id))->toBeFalse();

            return $p;
        });
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
