<?php

declare(strict_types=1);

use App\Models\Medico;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| El catálogo es del USUARIO, no de un paciente
|--------------------------------------------------------------------------
*/

it('lista los médicos propios y NO los de otro usuario', function (): void {
    $usuario = User::factory()->create();
    $mio = Medico::factory()->for($usuario, 'usuario')->create(['nombre' => 'Dra. Mía']);
    Medico::factory()->create(['nombre' => 'Dr. Ajeno']);

    $this->actingAs($usuario)
        ->get(route('medicos.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('catalogos/Medicos')
            ->has('registros', 1)
            ->where('registros.0.id', $mio->id)
        );
});

it('lista también las semillas compartidas', function (): void {
    $usuario = User::factory()->create();
    Medico::factory()->for($usuario, 'usuario')->create(['nombre' => 'Dra. Mía']);
    Medico::factory()->semilla()->create(['nombre' => 'Dr. Semilla']);

    $this->actingAs($usuario)
        ->get(route('medicos.index'))
        ->assertInertia(fn ($p) => $p->has('registros', 2));
});

it('ordena por nombre, que está cifrado y no se puede ordenar en SQL', function (): void {
    $usuario = User::factory()->create();
    Medico::factory()->for($usuario, 'usuario')->create(['nombre' => 'Zulema']);
    Medico::factory()->for($usuario, 'usuario')->create(['nombre' => 'ana']);
    Medico::factory()->for($usuario, 'usuario')->create(['nombre' => 'Mario']);

    $this->actingAs($usuario)
        ->get(route('medicos.index'))
        ->assertInertia(fn ($p) => $p
            ->where('registros.0.nombre', 'ana')
            ->where('registros.1.nombre', 'Mario')
            ->where('registros.2.nombre', 'Zulema')
        );
});

it('cifra el nombre y el teléfono en la base', function (): void {
    $medico = Medico::factory()->create([
        'nombre' => 'Dra. Confidencial',
        'telefono' => '1122334455',
    ]);

    $crudo = DB::table('medicos')->where('id', $medico->id)->first();

    expect($crudo->nombre)->not->toContain('Confidencial')
        ->and($crudo->telefono)->not->toContain('1122334455')
        ->and($medico->fresh()->nombre)->toBe('Dra. Confidencial');
});

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

it('crea un médico en el catálogo del usuario que lo carga', function (): void {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->post(route('medicos.store'), [
            'nombre' => 'Dr. Pérez',
            'especialidad' => 'Cardiología',
            'telefono' => '1155667788',
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    $medico = Medico::first();

    expect($medico->nombre)->toBe('Dr. Pérez')
        ->and($medico->usuario_id)->toBe($usuario->id)
        ->and($medico->esSemilla())->toBeFalse();
});

it('NO deja elegir en el catálogo de quién escribir', function (): void {
    // `usuario_id` no es fillable: mandarlo en el formulario no hace nada.
    $usuario = User::factory()->create();
    $otro = User::factory()->create();

    $this->actingAs($usuario)->post(route('medicos.store'), [
        'nombre' => 'Dr. Pérez',
        'usuario_id' => $otro->id,
    ]);

    expect(Medico::first()->usuario_id)->toBe($usuario->id);
});

it('exige el nombre', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('medicos.store'), ['nombre' => ''])
        ->assertSessionHasErrors('nombre');
});

it('rechaza un email mal escrito', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('medicos.store'), [
            'nombre' => 'Dr. Pérez',
            'email' => 'no-es-un-email',
        ])
        ->assertSessionHasErrors('email');
});

/*
|--------------------------------------------------------------------------
| Unicidad por índice ciego, acotada al usuario
|--------------------------------------------------------------------------
*/

it('no deja dos médicos con el mismo nombre en MI catálogo', function (): void {
    $usuario = User::factory()->create();
    Medico::factory()->for($usuario, 'usuario')->create(['nombre' => 'Dr. Pérez']);

    $this->actingAs($usuario)
        ->post(route('medicos.store'), ['nombre' => '  dr. PÉREZ  '])
        ->assertSessionHasErrors('nombre');

    expect(Medico::count())->toBe(1);
});

it('el mismo nombre SÍ puede existir en el catálogo de otro usuario', function (): void {
    $otro = User::factory()->create();
    Medico::factory()->for($otro, 'usuario')->create(['nombre' => 'Dr. Pérez']);

    $this->actingAs(User::factory()->create())
        ->post(route('medicos.store'), ['nombre' => 'Dr. Pérez'])
        ->assertSessionHasNoErrors();

    expect(Medico::count())->toBe(2);
});

it('editar sin cambiar el nombre no choca contra sí mismo', function (): void {
    $usuario = User::factory()->create();
    $medico = Medico::factory()->for($usuario, 'usuario')->create(['nombre' => 'Dr. Pérez']);

    $this->actingAs($usuario)
        ->put(route('medicos.update', $medico), [
            'nombre' => 'Dr. Pérez',
            'especialidad' => 'Clínica médica',
        ])
        ->assertSessionHasNoErrors();

    expect($medico->fresh()->especialidad)->toBe('Clínica médica');
});

/*
|--------------------------------------------------------------------------
| Semillas: se ven, no se editan, se duplican
|--------------------------------------------------------------------------
*/

it('nadie puede editar una semilla compartida', function (): void {
    $semilla = Medico::factory()->semilla()->create(['nombre' => 'Dr. Semilla']);

    $this->actingAs(User::factory()->create())
        ->put(route('medicos.update', $semilla), ['nombre' => 'Intento'])
        ->assertForbidden();

    expect($semilla->fresh()->nombre)->toBe('Dr. Semilla');
});

it('nadie puede borrar una semilla compartida', function (): void {
    $semilla = Medico::factory()->semilla()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('medicos.destroy', $semilla))
        ->assertForbidden();

    expect(Medico::find($semilla->id))->not->toBeNull();
});

it('duplicar una semilla la copia al catálogo propio', function (): void {
    $usuario = User::factory()->create();
    $semilla = Medico::factory()->semilla()->create([
        'nombre' => 'Dr. Semilla',
        'especialidad' => 'Cardiología',
    ]);

    $this->actingAs($usuario)
        ->post(route('medicos.duplicar', $semilla))
        ->assertRedirect()
        ->assertSessionHas('exito');

    $copia = Medico::where('usuario_id', $usuario->id)->first();

    expect($copia)->not->toBeNull()
        ->and($copia->nombre)->toBe('Dr. Semilla')
        ->and($copia->especialidad)->toBe('Cardiología')
        ->and($copia->id)->not->toBe($semilla->id)
        // La semilla queda intacta y sigue siendo de nadie.
        ->and($semilla->fresh()->usuario_id)->toBeNull();
});

it('la copia YA SÍ se puede editar', function (): void {
    $usuario = User::factory()->create();
    $semilla = Medico::factory()->semilla()->create(['nombre' => 'Dr. Semilla']);

    $this->actingAs($usuario)->post(route('medicos.duplicar', $semilla));
    $copia = Medico::where('usuario_id', $usuario->id)->firstOrFail();

    $this->actingAs($usuario)
        ->put(route('medicos.update', $copia), ['nombre' => 'Dr. Mío'])
        ->assertSessionHasNoErrors();

    expect($copia->fresh()->nombre)->toBe('Dr. Mío');
});

it('duplicar dos veces avisa en vez de explotar contra el UNIQUE', function (): void {
    $usuario = User::factory()->create();
    $semilla = Medico::factory()->semilla()->create(['nombre' => 'Dr. Semilla']);

    $this->actingAs($usuario)->post(route('medicos.duplicar', $semilla));

    $respuesta = $this->actingAs($usuario)->post(route('medicos.duplicar', $semilla));
    $respuesta->assertRedirect();

    expect($respuesta->getSession()->get('error'))->toContain('Ya tenés')
        ->and(Medico::where('usuario_id', $usuario->id)->count())->toBe(1);
});

it('no se puede duplicar el médico de otro usuario', function (): void {
    $ajeno = Medico::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('medicos.duplicar', $ajeno))
        ->assertForbidden();

    expect(Medico::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Editar y borrar lo propio
|--------------------------------------------------------------------------
*/

it('un usuario ajeno no puede editar ni borrar mi médico', function (): void {
    $medico = Medico::factory()->create(['nombre' => 'Dr. Mío']);
    $ajeno = User::factory()->create();

    $this->actingAs($ajeno)
        ->put(route('medicos.update', $medico), ['nombre' => 'Intento'])
        ->assertForbidden();

    $this->actingAs($ajeno)
        ->delete(route('medicos.destroy', $medico))
        ->assertForbidden();

    expect($medico->fresh()->nombre)->toBe('Dr. Mío');
});

it('el dueño borra su médico', function (): void {
    $usuario = User::factory()->create();
    $medico = Medico::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->delete(route('medicos.destroy', $medico))
        ->assertRedirect()
        ->assertSessionHas('exito');

    expect(Medico::find($medico->id))->toBeNull();
});

it('exige sesión', function (): void {
    $this->get(route('medicos.index'))->assertRedirect(route('login'));
});
