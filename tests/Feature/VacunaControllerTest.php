<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Vacuna;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| El catálogo es del USUARIO, no de un paciente (copiado de médicos)
|--------------------------------------------------------------------------
*/

it('lista las vacunas propias y NO las de otro usuario', function (): void {
    $usuario = User::factory()->create();
    $mia = Vacuna::factory()->for($usuario, 'usuario')->create(['nombre' => 'Mía']);
    Vacuna::factory()->create(['nombre' => 'Ajena']);

    $this->actingAs($usuario)
        ->get(route('vacunas.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('catalogos/Vacunas')
            ->has('registros', 1)
            ->where('registros.0.id', $mia->id)
        );
});

it('lista también las semillas compartidas', function (): void {
    $usuario = User::factory()->create();
    Vacuna::factory()->for($usuario, 'usuario')->create();
    Vacuna::factory()->semilla()->create();

    $this->actingAs($usuario)
        ->get(route('vacunas.index'))
        ->assertInertia(fn ($p) => $p->has('registros', 2));
});

it('ordena por nombre, que está cifrado', function (): void {
    $usuario = User::factory()->create();
    Vacuna::factory()->for($usuario, 'usuario')->create(['nombre' => 'Zeta']);
    Vacuna::factory()->for($usuario, 'usuario')->create(['nombre' => 'alfa']);

    $this->actingAs($usuario)
        ->get(route('vacunas.index'))
        ->assertInertia(fn ($p) => $p
            ->where('registros.0.nombre', 'alfa')
            ->where('registros.1.nombre', 'Zeta')
        );
});

it('cifra el nombre en la base', function (): void {
    $vacuna = Vacuna::factory()->create(['nombre' => 'Antigripal Confidencial']);

    $crudo = DB::table('vacunas')->where('id', $vacuna->id)->first();

    expect($crudo->nombre)->not->toContain('Confidencial')
        ->and($vacuna->fresh()->nombre)->toBe('Antigripal Confidencial');
});

/*
|--------------------------------------------------------------------------
| Alta, unicidad y semillas: mismo patrón que médicos y centros
|--------------------------------------------------------------------------
*/

it('crea una vacuna en el catálogo del usuario que la carga', function (): void {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->post(route('vacunas.store'), ['nombre' => 'Antigripal'])
        ->assertRedirect()
        ->assertSessionHas('exito');

    $vacuna = Vacuna::first();

    expect($vacuna->nombre)->toBe('Antigripal')
        ->and($vacuna->usuario_id)->toBe($usuario->id);
});

it('exige el nombre', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('vacunas.store'), ['nombre' => ''])
        ->assertSessionHasErrors('nombre');
});

it('no deja dos vacunas con el mismo nombre en MI catálogo', function (): void {
    $usuario = User::factory()->create();
    Vacuna::factory()->for($usuario, 'usuario')->create(['nombre' => 'Antigripal']);

    $this->actingAs($usuario)
        ->post(route('vacunas.store'), ['nombre' => '  ANTIGRIPAL  '])
        ->assertSessionHasErrors('nombre');

    expect(Vacuna::count())->toBe(1);
});

it('nadie puede editar ni borrar una semilla compartida', function (): void {
    $semilla = Vacuna::factory()->semilla()->create();
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->put(route('vacunas.update', $semilla), ['nombre' => 'Intento'])
        ->assertForbidden();

    $this->actingAs($usuario)
        ->delete(route('vacunas.destroy', $semilla))
        ->assertForbidden();
});

it('duplicar una semilla la copia al catálogo propio', function (): void {
    $usuario = User::factory()->create();
    $semilla = Vacuna::factory()->semilla()->create(['nombre' => 'Antigripal']);

    $this->actingAs($usuario)
        ->post(route('vacunas.duplicar', $semilla))
        ->assertRedirect()
        ->assertSessionHas('exito');

    $copia = Vacuna::where('usuario_id', $usuario->id)->first();

    expect($copia)->not->toBeNull()
        ->and($copia->nombre)->toBe('Antigripal')
        ->and($semilla->fresh()->usuario_id)->toBeNull();
});

it('un usuario ajeno no puede editar ni borrar mi vacuna', function (): void {
    $vacuna = Vacuna::factory()->create(['nombre' => 'Mía']);

    $this->actingAs(User::factory()->create())
        ->put(route('vacunas.update', $vacuna), ['nombre' => 'Intento'])
        ->assertForbidden();

    expect($vacuna->fresh()->nombre)->toBe('Mía');
});

it('exige sesión', function (): void {
    $this->get(route('vacunas.index'))->assertRedirect(route('login'));
});
