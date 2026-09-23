<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('crea al propietario en el pivote automáticamente al dar de alta un paciente', function (): void {
    $usuario = User::factory()->create();

    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    expect($paciente->rolDe($usuario))->toBe(RolPaciente::Propietario);
});

it('el nombre queda cifrado en la base', function (): void {
    $paciente = Paciente::factory()->create(['nombre' => 'Juan Pérez']);

    $crudo = DB::table('pacientes')->where('id', $paciente->id)->value('nombre');

    expect($crudo)->not->toContain('Juan Pérez');
    expect($paciente->fresh()->nombre)->toBe('Juan Pérez');
});

it('la edad se calcula, no se guarda', function (): void {
    $paciente = Paciente::factory()->create([
        'fecha_nacimiento' => now()->subYears(34)->subDays(10),
    ]);

    expect($paciente->edad)->toBe(34);

    // Y no existe columna edad en la tabla: se recalcula siempre.
    expect(DB::getSchemaBuilder()->hasColumn('pacientes', 'edad'))->toBeFalse();
});

it('el propietario puede ver, editar y borrar', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    expect($usuario->can('view', $paciente))->toBeTrue()
        ->and($usuario->can('update', $paciente))->toBeTrue()
        ->and($usuario->can('delete', $paciente))->toBeTrue();
});

it('un lector puede ver pero no editar ni borrar', function (): void {
    $paciente = Paciente::factory()->create();
    $lector = User::factory()->create();

    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    expect($lector->can('view', $paciente))->toBeTrue()
        ->and($lector->can('update', $paciente))->toBeFalse()
        ->and($lector->can('delete', $paciente))->toBeFalse()
        ->and($lector->can('registrarEventos', $paciente))->toBeFalse();
});

it('un cuidador puede editar pero no borrar', function (): void {
    $paciente = Paciente::factory()->create();
    $cuidador = User::factory()->create();

    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    expect($cuidador->can('update', $paciente))->toBeTrue()
        ->and($cuidador->can('registrarEventos', $paciente))->toBeTrue()
        ->and($cuidador->can('delete', $paciente))->toBeFalse();
});

it('un usuario sin ninguna fila en el pivote no ve nada del paciente', function (): void {
    $paciente = Paciente::factory()->create();
    $ajeno = User::factory()->create();

    expect($ajeno->can('view', $paciente))->toBeFalse()
        ->and($ajeno->can('update', $paciente))->toBeFalse();
});

/*
 * Este es el que justifica todo el diseño del pivote: la Policy nunca compara
 * usuario_id. Si alguien "simplificara" eso, este test lo agarra.
 */
it('la autorización no depende de pacientes.usuario_id', function (): void {
    $creador = User::factory()->create();
    $paciente = Paciente::factory()->for($creador, 'usuario')->create();

    // El creador queda como Propietario automáticamente (por el observer).
    // Si alguien lo saca del pivote pero pacientes.usuario_id sigue apuntando
    // a él, no debería poder ver nada: la comparación no puede ser por ahí.
    $paciente->cuidadores()->detach($creador->id);

    expect($paciente->fresh()->rolDe($creador))->toBeNull()
        ->and($creador->can('view', $paciente->fresh()))->toBeFalse();
});

it('cambia el paciente activo de la sesión', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->put(route('paciente-activo.update', $paciente))
        ->assertRedirect();

    expect(session('paciente_activo_id'))->toBe($paciente->id);
});

it('no deja marcar como activo un paciente ajeno', function (): void {
    $paciente = Paciente::factory()->create();
    $ajeno = User::factory()->create();

    $this->actingAs($ajeno)
        ->put(route('paciente-activo.update', $paciente))
        ->assertForbidden();
});

it('el paciente activo viaja en los props de Inertia', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Ana']);

    session(['paciente_activo_id' => $paciente->id]);

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p
            ->where('pacienteActivoId', $paciente->id)
            ->has('pacientes', 1)
            ->where('pacientes.0.nombre', 'Ana')
        );
});
