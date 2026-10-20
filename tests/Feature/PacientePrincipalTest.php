<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Enums\TipoAdjunto;
use App\Models\Cobertura;
use App\Models\Paciente;
use App\Models\User;
use App\Services\PacientePrincipal;

/*
|--------------------------------------------------------------------------
| El paciente principal: a quién llevan Credencial, Variables y Medicamentos
|--------------------------------------------------------------------------
*/

it('es la ficha que comparte el correo con la cuenta', function (): void {
    $usuario = User::factory()->create(['email' => 'pablo@ejemplo.test']);
    Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Abuela']);
    $propia = Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Yo', 'email' => 'Pablo@Ejemplo.test']);

    expect(PacientePrincipal::de($usuario)?->id)->toBe($propia->id);
});

it('sin ficha con su correo, es la primera de la que es propietario', function (): void {
    $usuario = User::factory()->create();
    $compartida = Paciente::factory()->create(['nombre' => 'Aaa compartida']);
    $compartida->cuidadores()->attach($usuario->id, ['rol' => RolPaciente::Cuidador->value]);
    $propia = Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Mamá']);

    expect(PacientePrincipal::de($usuario)?->id)->toBe($propia->id);
});

it('una ficha ajena con el mismo correo no cuenta', function (): void {
    $usuario = User::factory()->create(['email' => 'pablo@ejemplo.test']);
    Paciente::factory()->create(['email' => 'pablo@ejemplo.test']);
    $propia = Paciente::factory()->for($usuario, 'usuario')->create();

    expect(PacientePrincipal::de($usuario)?->id)->toBe($propia->id);
});

it('sin fichas no hay principal, y el menú manda a cargar una', function (): void {
    $usuario = User::factory()->create();

    expect(PacientePrincipal::de($usuario))->toBeNull();

    $this->actingAs($usuario)->get(route('variables.principal'))->assertRedirect(route('pacientes.index'));
    $this->actingAs($usuario)->get(route('medicamentos-del-paciente'))->assertRedirect(route('pacientes.index'));
});

it('Variables y Medicamentos del menú llevan a la ficha principal', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->get(route('variables.principal', ['cargar' => 3]))
        ->assertRedirect(route('pacientes.mediciones.index', ['paciente' => $paciente, 'cargar' => 3]));

    $this->actingAs($usuario)
        ->get(route('medicamentos-del-paciente'))
        ->assertRedirect(route('pacientes.tratamientos.index', $paciente));
});

/*
|--------------------------------------------------------------------------
| Credencial
|--------------------------------------------------------------------------
*/

it('muestra la credencial de las coberturas activas de la ficha principal', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $activa = Cobertura::factory()->for($paciente)->create(['entidad' => 'OSDE', 'activa' => true]);
    Cobertura::factory()->for($paciente)->create(['entidad' => 'Vieja', 'activa' => false]);
    $credencial = $activa->adjuntos()->create([
        'tipo' => TipoAdjunto::Credencial,
        'ruta' => 'x/y.cif',
        'nombre_original' => 'frente.jpg',
        'mime' => 'image/jpeg',
        'tamanio_bytes' => 10,
    ]);

    $this->actingAs($usuario)
        ->get(route('credencial.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('credencial/Index')
            ->where('paciente.id', $paciente->id)
            ->has('coberturas', 1)
            ->where('coberturas.0.entidad', 'OSDE')
            ->where('coberturas.0.credenciales.0.url', route('credenciales.show', $credencial)));
});

it('la credencial de una ficha ajena da 403', function (): void {
    $usuario = User::factory()->create();
    $ajena = Paciente::factory()->create();

    $this->actingAs($usuario)
        ->get(route('credencial.index', ['paciente' => $ajena->id]))
        ->assertForbidden();
});

it('sin fichas, la credencial dice sin datos', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('credencial.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('paciente', null)->has('coberturas', 0));
});

it('en Pacientes la ficha de la cuenta va primera, aunque por nombre fuera última', function (): void {
    $usuario = User::factory()->create(['email' => 'yo@ejemplo.test']);
    Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Abuela']);
    Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Zulema', 'email' => 'yo@ejemplo.test']);
    Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Mamá']);

    $this->actingAs($usuario)
        ->get(route('pacientes.index'))
        ->assertInertia(fn ($p) => $p
            ->where('pacientes.0.nombre', 'Zulema')
            ->where('pacientes.1.nombre', 'Abuela')
            ->where('pacientes.2.nombre', 'Mamá'));
});

it('el panel abre por defecto en la ficha de la cuenta, y la pone primera en el selector', function (): void {
    $usuario = User::factory()->create(['email' => 'yo@ejemplo.test']);
    Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Abuela']);
    $propia = Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Zulema', 'email' => 'yo@ejemplo.test']);

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p
            ->where('pacienteActivo.id', $propia->id)
            ->where('pacientes.0.id', $propia->id));
});
