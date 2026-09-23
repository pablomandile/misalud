<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Models\Paciente;
use App\Models\User;

it('lista solo los pacientes a los que el usuario tiene acceso', function (): void {
    $usuario = User::factory()->create();
    $mio = Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Ana']);
    Paciente::factory()->create(['nombre' => 'Otro, ajeno']);

    $this->actingAs($usuario)
        ->get(route('pacientes.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('pacientes/Index')
            ->has('pacientes', 1)
            ->where('pacientes.0.id', $mio->id)
        );
});

it('crea un paciente y queda como propietario', function (): void {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.store'), [
            'nombre' => 'María López',
            'fecha_nacimiento' => '1990-05-10',
            'sexo' => 'femenino',
            'grupo_sanguineo' => 'O+',
            'notas' => null,
        ])
        ->assertRedirect();

    $paciente = Paciente::first();

    expect($paciente->nombre)->toBe('María López')
        ->and($paciente->rolDe($usuario))->toBe(RolPaciente::Propietario);
});

it('exige el nombre al crear', function (): void {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.store'), ['nombre' => ''])
        ->assertSessionHasErrors('nombre');
});

it('un cuidador puede editar', function (): void {
    $paciente = Paciente::factory()->create(['nombre' => 'Original']);
    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->actingAs($cuidador)
        ->put(route('pacientes.update', $paciente), ['nombre' => 'Editado'])
        ->assertRedirect();

    expect($paciente->fresh()->nombre)->toBe('Editado');
});

it('un lector NO puede editar', function (): void {
    $paciente = Paciente::factory()->create(['nombre' => 'Original']);
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->put(route('pacientes.update', $paciente), ['nombre' => 'Intento'])
        ->assertForbidden();

    expect($paciente->fresh()->nombre)->toBe('Original');
});

it('un usuario ajeno no puede editar ni ver por la URL directa', function (): void {
    $paciente = Paciente::factory()->create();
    $ajeno = User::factory()->create();

    $this->actingAs($ajeno)
        ->put(route('pacientes.update', $paciente), ['nombre' => 'Intento'])
        ->assertForbidden();
});

it('solo el propietario puede eliminar', function (): void {
    $paciente = Paciente::factory()->create();
    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->actingAs($cuidador)
        ->delete(route('pacientes.destroy', $paciente))
        ->assertForbidden();

    expect(Paciente::find($paciente->id))->not->toBeNull();
});

it('el propietario elimina y limpia el paciente activo si era el elegido', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    session(['paciente_activo_id' => $paciente->id]);

    $this->actingAs($usuario)
        ->delete(route('pacientes.destroy', $paciente))
        ->assertRedirect();

    expect(Paciente::find($paciente->id))->toBeNull()
        ->and(session('paciente_activo_id'))->toBeNull();
});

it('el flash de éxito llega con el nombre del paciente', function (): void {
    $usuario = User::factory()->create();

    $respuesta = $this->actingAs($usuario)->post(route('pacientes.store'), [
        'nombre' => 'Carla',
    ]);

    expect($respuesta->getSession()->get('exito'))->toContain('Carla');
});
