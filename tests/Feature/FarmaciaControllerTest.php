<?php

declare(strict_types=1);

use App\Models\Farmacia;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('lista las farmacias propias y no las de otro', function (): void {
    $usuario = User::factory()->create();
    Farmacia::factory()->for($usuario, 'usuario')->create(['nombre' => 'Farmacia del Pueblo']);
    Farmacia::factory()->create(['nombre' => 'Ajena']);

    $this->actingAs($usuario)->get(route('farmacias.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('catalogos/Farmacias')
            ->has('registros', 1)
            ->where('registros.0.nombre', 'Farmacia del Pueblo'));
});

it('guarda todos los datos de contacto, cifrados', function (): void {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->post(route('farmacias.store'), [
            'nombre' => 'Farmacia del Pueblo',
            'direccion' => 'San Martín 123',
            'telefono' => '4555-1234',
            'whatsapp' => '11 2345 6789',
            'email' => ' Pedidos@Pueblo.test ',
            'web' => 'www.pueblo.test',
            'horarios' => 'Lunes a sábado de 8 a 21',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $farmacia = Farmacia::where('usuario_id', $usuario->id)->sole();
    $fila = (array) DB::table('farmacias')->where('id', $farmacia->id)->first();

    expect($farmacia->email)->toBe('pedidos@pueblo.test')
        ->and($farmacia->web)->toBe('https://www.pueblo.test')
        ->and($farmacia->enlaceWhatsapp())->toBe('https://wa.me/5491123456789')
        ->and($fila['nombre'])->not->toContain('Pueblo')
        ->and($fila['direccion'])->not->toContain('Martín')
        ->and($fila['horarios'])->not->toContain('sábado');
});

it('no deja cargar dos veces la misma farmacia', function (): void {
    $usuario = User::factory()->create();
    Farmacia::factory()->for($usuario, 'usuario')->create(['nombre' => 'Farmacia del Pueblo']);

    $this->actingAs($usuario)
        ->post(route('farmacias.store'), ['nombre' => 'farmacia del pueblo'])
        ->assertSessionHasErrors('nombre');
});

it('edita y borra de verdad', function (): void {
    $usuario = User::factory()->create();
    $farmacia = Farmacia::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->put(route('farmacias.update', $farmacia), ['nombre' => 'Otra', 'horarios' => '24 horas'])
        ->assertSessionHasNoErrors();

    expect($farmacia->fresh()->horarios)->toBe('24 horas');

    $this->actingAs($usuario)->delete(route('farmacias.destroy', $farmacia))->assertRedirect();

    expect(DB::table('farmacias')->where('id', $farmacia->id)->exists())->toBeFalse();
});

it('la farmacia de otro no se edita ni se borra', function (): void {
    $farmacia = Farmacia::factory()->create();
    $extranio = User::factory()->create();

    $this->actingAs($extranio)->put(route('farmacias.update', $farmacia), ['nombre' => 'X'])->assertForbidden();
    $this->actingAs($extranio)->delete(route('farmacias.destroy', $farmacia))->assertForbidden();
});
