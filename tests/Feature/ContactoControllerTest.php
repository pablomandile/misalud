<?php

declare(strict_types=1);

use App\Enums\EstadoEnvio;
use App\Enums\RolPaciente;
use App\Models\Cobertura;
use App\Models\Contacto;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->usuario = User::factory()->create();
});

/**
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function datosDeContacto(array $cambios = []): array
{
    return [
        'nombre' => 'Farmacia Central',
        'email' => 'recetas@central.test',
        'tipo' => 'farmacia',
        ...$cambios,
    ];
}

it('agrega un contacto a la libreta del usuario', function (): void {
    $this->actingAs($this->usuario)
        ->post(route('contactos.store'), datosDeContacto())
        ->assertSessionHas('exito');

    $contacto = Contacto::sole();

    expect($contacto->usuario_id)->toBe($this->usuario->id)
        ->and($contacto->nombre)->toBe('Farmacia Central')
        ->and($contacto->email)->toBe('recetas@central.test');
});

it('guarda la dirección en minúscula y sin espacios', function (): void {
    $this->actingAs($this->usuario)
        ->post(route('contactos.store'), datosDeContacto(['email' => '  Recetas@Central.TEST ']));

    expect(Contacto::sole()->email)->toBe('recetas@central.test');
});

it('guarda cifrados el nombre y la dirección', function (): void {
    Contacto::factory()->for($this->usuario, 'usuario')->create([
        'nombre' => 'Dr. Reservado',
        'email' => 'dr@reservado.test',
    ]);

    $fila = DB::table('contactos')->sole();

    expect($fila->nombre)->not->toContain('Reservado')
        ->and($fila->email)->not->toContain('reservado');
});

it('lista solo los contactos propios, ordenados por nombre', function (): void {
    Contacto::factory()->for($this->usuario, 'usuario')->create(['nombre' => 'Óptica Sur']);
    Contacto::factory()->for($this->usuario, 'usuario')->create(['nombre' => 'farmacia Norte']);
    Contacto::factory()->create();   // de otro

    // El orden es en PHP: `nombre` está cifrado.
    $this->actingAs($this->usuario)
        ->get(route('contactos.index'))
        ->assertInertia(fn ($pagina) => $pagina
            ->component('contactos/Index')
            ->has('contactos', 2)
            ->where('contactos.0.nombre', 'farmacia Norte')
            ->where('contactos.1.nombre', 'Óptica Sur'));
});

it('no deja cargar dos veces la misma dirección', function (): void {
    Contacto::factory()->for($this->usuario, 'usuario')->create(['email' => 'recetas@central.test']);

    $this->actingAs($this->usuario)
        ->post(route('contactos.store'), datosDeContacto(['email' => 'RECETAS@central.test']))
        ->assertSessionHasErrors('email');
});

it('la misma dirección sí puede estar en dos libretas distintas', function (): void {
    Contacto::factory()->create(['email' => 'recetas@central.test']);

    $this->actingAs($this->usuario)
        ->post(route('contactos.store'), datosDeContacto())
        ->assertSessionHasNoErrors();
});

it('⚠️ borrar un contacto y volver a cargarlo con el mismo mail NO revienta', function (): void {
    /*
     * Es el bug que tienen hoy los catálogos: el registro borrado queda en la
     * papelera ocupando su hash, la validación no lo ve, y el UNIQUE de la base sí
     * -> 500. Los contactos no tienen soft deletes justamente para no repetirlo.
     */
    $this->actingAs($this->usuario)->post(route('contactos.store'), datosDeContacto());
    $this->actingAs($this->usuario)->delete(route('contactos.destroy', Contacto::sole()));

    $this->actingAs($this->usuario)
        ->post(route('contactos.store'), datosDeContacto())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(Contacto::count())->toBe(1);
});

it('borrar un contacto no borra lo que se le mandó', function (): void {
    $contacto = Contacto::factory()->for($this->usuario, 'usuario')->create([
        'nombre' => 'Farmacia Central',
        'email' => 'recetas@central.test',
    ]);
    $envio = $this->usuario->envios()->create([
        'contacto_id' => $contacto->id,
        'destinatario' => 'recetas@central.test',
        'destinatario_nombre' => 'Farmacia Central',
        'asunto' => 'Receta',
        'estado' => EstadoEnvio::Enviado,
    ]);

    $this->actingAs($this->usuario)->delete(route('contactos.destroy', $contacto));

    expect($envio->fresh()->contacto_id)->toBeNull()
        // La foto del momento sigue diciendo a dónde salió.
        ->and($envio->fresh()->destinatario)->toBe('recetas@central.test')
        ->and($envio->fresh()->destinatario_nombre)->toBe('Farmacia Central');
});

it('edita un contacto sin chocar consigo mismo', function (): void {
    $contacto = Contacto::factory()->for($this->usuario, 'usuario')->create(['email' => 'a@b.test']);

    $this->actingAs($this->usuario)
        ->put(route('contactos.update', $contacto), datosDeContacto(['email' => 'a@b.test', 'nombre' => 'Otro']))
        ->assertSessionHasNoErrors();

    expect($contacto->fresh()->nombre)->toBe('Otro');
});

it('no deja editar ni borrar el contacto de otro', function (): void {
    $ajeno = Contacto::factory()->create();

    $this->actingAs($this->usuario)
        ->put(route('contactos.update', $ajeno), datosDeContacto())
        ->assertForbidden();

    $this->actingAs($this->usuario)
        ->delete(route('contactos.destroy', $ajeno))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| "Pueden salir de una cobertura"
|--------------------------------------------------------------------------
*/

it('precarga el alta con el nombre de una cobertura propia', function (): void {
    $paciente = Paciente::factory()->for($this->usuario, 'usuario')->create();
    $cobertura = Cobertura::factory()->for($paciente)->create(['entidad' => 'OSDE']);

    $this->actingAs($this->usuario)
        ->get(route('contactos.index', ['cobertura' => $cobertura->id]))
        ->assertInertia(fn ($pagina) => $pagina
            ->where('precarga.nombre', 'OSDE')
            ->where('precarga.tipo', 'obra_social'));
});

it('⚠️ una cobertura ajena se ignora en silencio, sin 403', function (): void {
    // Con un 403, la pantalla confirmaría que ese id existe en la ficha de otro.
    $cobertura = Cobertura::factory()->create(['entidad' => 'Ajena']);

    $this->actingAs($this->usuario)
        ->get(route('contactos.index', ['cobertura' => $cobertura->id]))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->where('precarga', null));
});

it('un lector de la ficha también puede guardarse la cobertura como contacto', function (): void {
    $paciente = Paciente::factory()->create();
    $paciente->cuidadores()->attach($this->usuario, ['rol' => RolPaciente::Lector->value]);
    $cobertura = Cobertura::factory()->for($paciente)->create(['entidad' => 'PAMI']);

    $this->actingAs($this->usuario)
        ->get(route('contactos.index', ['cobertura' => $cobertura->id]))
        ->assertInertia(fn ($pagina) => $pagina->where('precarga.nombre', 'PAMI'));
});

it('muestra el historial de envíos, el último primero', function (): void {
    foreach (['Viejo', 'Nuevo'] as $i => $asunto) {
        $envio = $this->usuario->envios()->create([
            'destinatario' => 'a@b.test',
            'destinatario_nombre' => 'A',
            'asunto' => $asunto,
            'estado' => EstadoEnvio::Enviado,
        ]);
        $envio->forceFill(['created_at' => now()->subDays(5 - $i)])->save();
    }

    $this->actingAs($this->usuario)
        ->get(route('contactos.index'))
        ->assertInertia(fn ($pagina) => $pagina
            ->has('envios', 2)
            ->where('envios.0.asunto', 'Nuevo'));
});
