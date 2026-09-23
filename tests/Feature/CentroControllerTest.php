<?php

declare(strict_types=1);

use App\Enums\TipoCentro;
use App\Models\Centro;
use App\Models\Medico;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| El catálogo es del USUARIO, no de un paciente (copiado de médicos)
|--------------------------------------------------------------------------
*/

it('lista los centros propios y NO los de otro usuario', function (): void {
    $usuario = User::factory()->create();
    $mio = Centro::factory()->for($usuario, 'usuario')->create(['nombre' => 'Consultorio Mío']);
    Centro::factory()->create(['nombre' => 'Consultorio Ajeno']);

    $this->actingAs($usuario)
        ->get(route('centros.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('catalogos/Centros')
            ->has('registros', 1)
            ->where('registros.0.id', $mio->id)
        );
});

it('lista también las semillas compartidas', function (): void {
    $usuario = User::factory()->create();
    Centro::factory()->for($usuario, 'usuario')->create();
    Centro::factory()->semilla()->create();

    $this->actingAs($usuario)
        ->get(route('centros.index'))
        ->assertInertia(fn ($p) => $p->has('registros', 2));
});

it('ordena por nombre, que está cifrado', function (): void {
    $usuario = User::factory()->create();
    Centro::factory()->for($usuario, 'usuario')->create(['nombre' => 'Zeta']);
    Centro::factory()->for($usuario, 'usuario')->create(['nombre' => 'alfa']);

    $this->actingAs($usuario)
        ->get(route('centros.index'))
        ->assertInertia(fn ($p) => $p
            ->where('registros.0.nombre', 'alfa')
            ->where('registros.1.nombre', 'Zeta')
        );
});

it('cifra el nombre y la dirección en la base', function (): void {
    $centro = Centro::factory()->create([
        'nombre' => 'Clínica Confidencial',
        'direccion' => 'Calle Falsa 123',
    ]);

    $crudo = DB::table('centros')->where('id', $centro->id)->first();

    expect($crudo->nombre)->not->toContain('Confidencial')
        ->and($crudo->direccion)->not->toContain('Falsa')
        ->and($centro->fresh()->nombre)->toBe('Clínica Confidencial');
});

/*
|--------------------------------------------------------------------------
| Alta, unicidad y semillas: mismo patrón que médicos
|--------------------------------------------------------------------------
*/

it('crea un centro en el catálogo del usuario que lo carga', function (): void {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->post(route('centros.store'), [
            'nombre' => 'Consultorio Central',
            'tipo' => TipoCentro::Consultorio->value,
            'direccion' => 'Av. Siempre Viva 742',
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    $centro = Centro::first();

    expect($centro->nombre)->toBe('Consultorio Central')
        ->and($centro->tipo)->toBe(TipoCentro::Consultorio)
        ->and($centro->usuario_id)->toBe($usuario->id);
});

it('exige el nombre y un tipo válido', function (): void {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->post(route('centros.store'), ['nombre' => '', 'tipo' => TipoCentro::Consultorio->value])
        ->assertSessionHasErrors('nombre');

    $this->actingAs($usuario)
        ->post(route('centros.store'), ['nombre' => 'Algo', 'tipo' => 'inventado'])
        ->assertSessionHasErrors('tipo');
});

it('no deja dos centros con el mismo nombre en MI catálogo', function (): void {
    $usuario = User::factory()->create();
    Centro::factory()->for($usuario, 'usuario')->create(['nombre' => 'Consultorio Central']);

    $this->actingAs($usuario)
        ->post(route('centros.store'), [
            'nombre' => '  CONSULTORIO central  ',
            'tipo' => TipoCentro::Consultorio->value,
        ])
        ->assertSessionHasErrors('nombre');

    expect(Centro::count())->toBe(1);
});

it('nadie puede editar ni borrar una semilla compartida', function (): void {
    $semilla = Centro::factory()->semilla()->create();
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->put(route('centros.update', $semilla), [
            'nombre' => 'Intento',
            'tipo' => TipoCentro::Consultorio->value,
        ])
        ->assertForbidden();

    $this->actingAs($usuario)
        ->delete(route('centros.destroy', $semilla))
        ->assertForbidden();
});

it('duplicar una semilla la copia al catálogo propio', function (): void {
    $usuario = User::factory()->create();
    $semilla = Centro::factory()->semilla()->create(['nombre' => 'Hospital Central']);

    $this->actingAs($usuario)
        ->post(route('centros.duplicar', $semilla))
        ->assertRedirect()
        ->assertSessionHas('exito');

    $copia = Centro::where('usuario_id', $usuario->id)->first();

    expect($copia)->not->toBeNull()
        ->and($copia->nombre)->toBe('Hospital Central')
        ->and($semilla->fresh()->usuario_id)->toBeNull();
});

it('un usuario ajeno no puede editar ni borrar mi centro', function (): void {
    $centro = Centro::factory()->create(['nombre' => 'Mi Consultorio']);

    $this->actingAs(User::factory()->create())
        ->put(route('centros.update', $centro), [
            'nombre' => 'Intento',
            'tipo' => TipoCentro::Consultorio->value,
        ])
        ->assertForbidden();

    expect($centro->fresh()->nombre)->toBe('Mi Consultorio');
});

it('exige sesión', function (): void {
    $this->get(route('centros.index'))->assertRedirect(route('login'));
});

/*
|--------------------------------------------------------------------------
| El pivote centro_medico: lo que médicos no tenía
|--------------------------------------------------------------------------
*/

it('vincula médicos propios al crear el centro', function (): void {
    $usuario = User::factory()->create();
    $medico1 = Medico::factory()->for($usuario, 'usuario')->create();
    $medico2 = Medico::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->post(route('centros.store'), [
            'nombre' => 'Consultorio Central',
            'tipo' => TipoCentro::Consultorio->value,
            'medicos' => [$medico1->id, $medico2->id],
        ])
        ->assertSessionHasNoErrors();

    $centro = Centro::first();

    expect($centro->medicos()->pluck('medicos.id')->sort()->values()->all())
        ->toBe([$medico1->id, $medico2->id]);
});

it('el listado trae los médicos vinculados, ya con el nombre en claro', function (): void {
    $usuario = User::factory()->create();
    $medico = Medico::factory()->for($usuario, 'usuario')->create(['nombre' => 'Dr. Vinculado']);
    $centro = Centro::factory()->for($usuario, 'usuario')->create();
    $centro->medicos()->attach($medico);

    $this->actingAs($usuario)
        ->get(route('centros.index'))
        ->assertInertia(fn ($p) => $p
            ->has('registros.0.medicos', 1)
            ->where('registros.0.medicos.0.nombre', 'Dr. Vinculado')
        );
});

it('editar re-sincroniza: saca los que ya no están marcados', function (): void {
    $usuario = User::factory()->create();
    $medico1 = Medico::factory()->for($usuario, 'usuario')->create();
    $medico2 = Medico::factory()->for($usuario, 'usuario')->create();
    $centro = Centro::factory()->for($usuario, 'usuario')->create();
    $centro->medicos()->attach([$medico1->id, $medico2->id]);

    $this->actingAs($usuario)
        ->put(route('centros.update', $centro), [
            'nombre' => $centro->nombre,
            'tipo' => $centro->tipo->value,
            'medicos' => [$medico1->id],
        ])
        ->assertSessionHasNoErrors();

    expect($centro->medicos()->pluck('medicos.id')->all())->toBe([$medico1->id]);
});

it('editar sin mandar medicos desvincula a todos', function (): void {
    // Un array ausente en el request es "ninguno marcado", no "no toques nada".
    $usuario = User::factory()->create();
    $medico = Medico::factory()->for($usuario, 'usuario')->create();
    $centro = Centro::factory()->for($usuario, 'usuario')->create();
    $centro->medicos()->attach($medico);

    $this->actingAs($usuario)->put(route('centros.update', $centro), [
        'nombre' => $centro->nombre,
        'tipo' => $centro->tipo->value,
    ]);

    expect($centro->medicos()->count())->toBe(0);
});

it('NO deja vincular el médico de otro usuario', function (): void {
    $usuario = User::factory()->create();
    $medicoAjeno = Medico::factory()->create();

    $this->actingAs($usuario)
        ->post(route('centros.store'), [
            'nombre' => 'Consultorio Central',
            'tipo' => TipoCentro::Consultorio->value,
            'medicos' => [$medicoAjeno->id],
        ])
        ->assertSessionHasErrors('medicos.0');

    expect(Centro::count())->toBe(0);
});

it('SÍ deja vincular un médico que es semilla compartida', function (): void {
    $usuario = User::factory()->create();
    $semilla = Medico::factory()->semilla()->create();

    $this->actingAs($usuario)
        ->post(route('centros.store'), [
            'nombre' => 'Consultorio Central',
            'tipo' => TipoCentro::Consultorio->value,
            'medicos' => [$semilla->id],
        ])
        ->assertSessionHasNoErrors();

    expect(Centro::first()->medicos()->count())->toBe(1);
});

it('duplicar una semilla NO copia sus médicos vinculados', function (): void {
    $usuario = User::factory()->create();
    $semilla = Centro::factory()->semilla()->create();
    $medicoDelPublicador = Medico::factory()->create();
    $semilla->medicos()->attach($medicoDelPublicador);

    $this->actingAs($usuario)->post(route('centros.duplicar', $semilla));

    $copia = Centro::where('usuario_id', $usuario->id)->firstOrFail();

    expect($copia->medicos()->count())->toBe(0);
});

it('borrar (soft) un centro NO toca sus vínculos', function (): void {
    /*
     * `cascadeOnDelete()` es una restricción de MySQL, y solo dispara con un
     * DELETE real. `destroy()` hace un soft delete -pone `deleted_at`-, así
     * que la fila de `centros` sigue ahí y el vínculo con ella también:
     * es lo correcto, porque un soft delete es recuperable y perder los
     * médicos vinculados en el camino sería una pérdida silenciosa de datos
     * que nadie borró a propósito.
     */
    $usuario = User::factory()->create();
    $medico = Medico::factory()->for($usuario, 'usuario')->create();
    $centro = Centro::factory()->for($usuario, 'usuario')->create();
    $centro->medicos()->attach($medico);

    $this->actingAs($usuario)->delete(route('centros.destroy', $centro));

    expect($centro->fresh()->deleted_at)->not->toBeNull()
        ->and(DB::table('centro_medico')->where('centro_id', $centro->id)->count())->toBe(1);
});

it('el forceDelete SÍ borra los vínculos en cascada', function (): void {
    // Acá sí es un DELETE real, y ahí la restricción de MySQL entra a jugar.
    $medico = Medico::factory()->create();
    $centro = Centro::factory()->create();
    $centro->medicos()->attach($medico);

    $centro->forceDelete();

    expect(DB::table('centro_medico')->where('centro_id', $centro->id)->count())->toBe(0)
        ->and(Medico::find($medico->id))->not->toBeNull();
});

it('un médico se ve desde su propia relación centros()', function (): void {
    $usuario = User::factory()->create();
    $medico = Medico::factory()->for($usuario, 'usuario')->create();
    $centro = Centro::factory()->for($usuario, 'usuario')->create(['nombre' => 'Consultorio Central']);
    $centro->medicos()->attach($medico);

    expect($medico->centros()->first()?->nombre)->toBe('Consultorio Central');
});
