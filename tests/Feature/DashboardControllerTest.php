<?php

declare(strict_types=1);

use App\Enums\TipoAdjunto;
use App\Models\Cobertura;
use App\Models\Paciente;
use App\Models\User;

it('sin pacientes, no ofrece ninguna credencial', function (): void {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('pacienteActivo', null)
            ->where('credenciales', [])
        );
});

it('sin paciente activo en sesión, usa el primero por nombre', function (): void {
    $usuario = User::factory()->create();
    Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Zoe']);
    $primero = Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Ana']);

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p
            ->where('pacienteActivo.id', $primero->id)
            ->where('pacienteActivo.nombre', 'Ana')
        );
});

it('respeta el paciente activo de la sesión', function (): void {
    $usuario = User::factory()->create();
    Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Ana']);
    $activo = Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Zoe']);

    $this->actingAs($usuario)
        ->withSession(['paciente_activo_id' => $activo->id])
        ->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p->where('pacienteActivo.id', $activo->id));
});

it('ignora un paciente activo en sesión que ya no es accesible', function (): void {
    $usuario = User::factory()->create();
    $propio = Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Ana']);
    $ajeno = Paciente::factory()->create();

    $this->actingAs($usuario)
        ->withSession(['paciente_activo_id' => $ajeno->id])
        ->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p->where('pacienteActivo.id', $propio->id));
});

it('sin coberturas, la credencial dice "sin datos" y no explota', function (): void {
    $usuario = User::factory()->create();
    Paciente::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('credenciales', []));
});

it('ofrece la credencial de una cobertura activa', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $cobertura = Cobertura::factory()->for($paciente)->create([
        'entidad' => 'OSDE',
        'activa' => true,
    ]);
    $adjunto = $cobertura->adjuntos()->create([
        'tipo' => TipoAdjunto::Credencial,
        'ruta' => 'coberturas/1/frente.cif',
        'nombre_original' => 'frente.jpg',
        'mime' => 'image/jpeg',
        'tamanio_bytes' => 2048,
    ]);

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p
            ->has('credenciales', 1)
            ->where('credenciales.0.entidad', 'OSDE')
            ->where('credenciales.0.nombre', 'frente.jpg')
            ->where('credenciales.0.url', route('credenciales.show', $adjunto))
        );
});

it('NO ofrece la credencial de una cobertura inactiva', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $cobertura = Cobertura::factory()->for($paciente)->create(['activa' => false]);
    $cobertura->adjuntos()->create([
        'tipo' => TipoAdjunto::Credencial,
        'ruta' => 'coberturas/1/frente.cif',
        'nombre_original' => 'frente.jpg',
        'mime' => 'image/jpeg',
        'tamanio_bytes' => 2048,
    ]);

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p->where('credenciales', []));
});

it('NO ofrece un adjunto que no es de tipo credencial', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $cobertura = Cobertura::factory()->for($paciente)->create(['activa' => true]);
    $cobertura->adjuntos()->create([
        'tipo' => TipoAdjunto::Otro,
        'ruta' => 'coberturas/1/algo.cif',
        'nombre_original' => 'algo.pdf',
        'mime' => 'application/pdf',
        'tamanio_bytes' => 1024,
    ]);

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p->where('credenciales', []));
});

it('exige sesión para ver el panel', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
