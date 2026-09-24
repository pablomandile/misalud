<?php

declare(strict_types=1);

use App\Models\Medicion;
use App\Models\Paciente;
use App\Models\TipoMedicion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| El quinto catálogo: mismo patrón que los otros cuatro
|--------------------------------------------------------------------------
*/

it('lista los tipos propios y las semillas, no los ajenos', function (): void {
    $usuario = User::factory()->create();
    TipoMedicion::factory()->for($usuario, 'usuario')->create();
    TipoMedicion::factory()->semilla()->create();
    TipoMedicion::factory()->create();

    $this->actingAs($usuario)
        ->get(route('tipos-medicion.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('catalogos/TiposMedicion')
            ->has('registros', 2)
        );
});

it('cifra el nombre y la unidad, pero NO los rangos de referencia', function (): void {
    /*
     * Los rangos quedan en claro a propósito: no son el dato clínico de
     * nadie, son una propiedad del tipo -que encima puede ser una semilla
     * compartida por todos-. Y en claro se leen como números.
     */
    $tipo = TipoMedicion::factory()->create([
        'nombre' => 'Glucemia Confidencial',
        'unidad' => 'mg/dl',
        'min_normal' => 70,
        'max_normal' => 110,
    ]);

    $crudo = DB::table('tipos_medicion')->where('id', $tipo->id)->first();

    expect($crudo->nombre)->not->toContain('Confidencial')
        ->and($crudo->unidad)->not->toContain('mg/dl')
        ->and((float) $crudo->min_normal)->toBe(70.0)
        ->and($tipo->fresh()->nombre)->toBe('Glucemia Confidencial');
});

it('crea un tipo en el catálogo de quien lo carga', function (): void {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->post(route('tipos-medicion.store'), [
            'nombre' => 'Peso',
            'unidad' => 'kg',
            'decimales' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    $tipo = TipoMedicion::first();

    expect($tipo->nombre)->toBe('Peso')
        ->and($tipo->usuario_id)->toBe($usuario->id)
        ->and($tipo->tieneValorSecundario())->toBeFalse();
});

it('exige nombre, unidad y decimales', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('tipos-medicion.store'), ['nombre' => '', 'unidad' => ''])
        ->assertSessionHasErrors(['nombre', 'unidad', 'decimales']);
});

it('no deja dos tipos con el mismo nombre en MI catálogo', function (): void {
    $usuario = User::factory()->create();
    TipoMedicion::factory()->for($usuario, 'usuario')->create(['nombre' => 'Peso']);

    $this->actingAs($usuario)
        ->post(route('tipos-medicion.store'), [
            'nombre' => '  PESO  ',
            'unidad' => 'kg',
            'decimales' => 1,
        ])
        ->assertSessionHasErrors('nombre');
});

it('nadie edita ni borra una semilla compartida', function (): void {
    $semilla = TipoMedicion::factory()->semilla()->create();
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->put(route('tipos-medicion.update', $semilla), [
            'nombre' => 'Intento',
            'unidad' => 'kg',
            'decimales' => 0,
        ])
        ->assertForbidden();

    $this->actingAs($usuario)
        ->delete(route('tipos-medicion.destroy', $semilla))
        ->assertForbidden();
});

it('duplicar una semilla la copia con sus rangos', function (): void {
    $usuario = User::factory()->create();
    $semilla = TipoMedicion::factory()->deDosValores()->semilla()->create();

    $this->actingAs($usuario)
        ->post(route('tipos-medicion.duplicar', $semilla))
        ->assertSessionHas('exito');

    $copia = TipoMedicion::where('usuario_id', $usuario->id)->firstOrFail();

    expect($copia->etiqueta_secundaria)->toBe('Diastólica')
        ->and($copia->min_normal)->toBe(90.0)
        ->and($copia->max_normal_secundario)->toBe(90.0);
});

it('exige sesión', function (): void {
    $this->get(route('tipos-medicion.index'))->assertRedirect(route('login'));
});

/*
|--------------------------------------------------------------------------
| Los dos valores: la etiqueta secundaria es la que manda
|--------------------------------------------------------------------------
*/

it('un tipo tiene dos valores si y solo si declara la etiqueta del segundo', function (): void {
    // Es la etiqueta y no la unidad: en presión las dos unidades son mmHg,
    // así que `unidad_secundaria` queda vacía y no distinguiría nada.
    $conDos = TipoMedicion::factory()->deDosValores()->create();
    $conUno = TipoMedicion::factory()->create();

    expect($conDos->tieneValorSecundario())->toBeTrue()
        ->and($conUno->tieneValorSecundario())->toBeFalse();
});

it('la unidad del segundo valor cae en la principal si no declara una', function (): void {
    $presion = TipoMedicion::factory()->deDosValores()->create(['unidad_secundaria' => null]);

    expect($presion->unidadSecundariaVisible())->toBe('mmHg');
});

it('el primer campo se llama "Valor" mientras nadie lo rotule', function (): void {
    expect(TipoMedicion::factory()->create()->etiquetaPrincipalVisible())->toBe('Valor')
        ->and(TipoMedicion::factory()->deDosValores()->create()->etiquetaPrincipalVisible())
        ->toBe('Sistólica');
});

it('rechaza un rango secundario sin etiqueta secundaria', function (): void {
    // Describiría un valor que este tipo no pide nunca: queda guardado, no
    // lo lee nadie, y reaparece como una banda equivocada en el gráfico.
    $this->actingAs(User::factory()->create())
        ->post(route('tipos-medicion.store'), [
            'nombre' => 'Peso',
            'unidad' => 'kg',
            'decimales' => 1,
            'min_normal_secundario' => '60',
        ])
        ->assertSessionHasErrors('min_normal_secundario');
});

it('acepta los rangos escritos con coma decimal', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('tipos-medicion.store'), [
            'nombre' => 'Temperatura',
            'unidad' => '°C',
            'decimales' => 1,
            'min_normal' => '36,0',
            'max_normal' => '37,5',
        ])
        ->assertSessionHasNoErrors();

    expect(TipoMedicion::first()->max_normal)->toBe(37.5);
});

it('rechaza un máximo menor que el mínimo', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('tipos-medicion.store'), [
            'nombre' => 'Peso',
            'unidad' => 'kg',
            'decimales' => 1,
            'min_normal' => '100',
            'max_normal' => '50',
        ])
        ->assertSessionHasErrors('max_normal');
});

it('formatea con los decimales que declara', function (): void {
    $peso = TipoMedicion::factory()->create(['decimales' => 1]);
    $presion = TipoMedicion::factory()->deDosValores()->create();

    expect($peso->formatear(72.5))->toBe('72,5')
        ->and($presion->formatear(120.0))->toBe('120')
        ->and($peso->formatear(null))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Borrar un tipo que ya tiene mediciones
|--------------------------------------------------------------------------
*/

it('NO deja borrar un tipo con mediciones cargadas', function (): void {
    /*
     * La alternativa sería perder datos clínicos por una operación de
     * catálogo: con cascade se irían las mediciones, y con un soft delete a
     * secas quedarían apuntando a un tipo que ninguna pantalla resuelve.
     */
    $usuario = User::factory()->create();
    $tipo = TipoMedicion::factory()->for($usuario, 'usuario')->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    Medicion::factory()->for($paciente)->for($tipo, 'tipo')->create();

    $respuesta = $this->actingAs($usuario)->delete(route('tipos-medicion.destroy', $tipo));

    expect($respuesta->getSession()->get('error'))->toContain('ya tiene mediciones')
        ->and(TipoMedicion::find($tipo->id))->not->toBeNull();
});

it('sí deja borrar un tipo que nadie usó', function (): void {
    $usuario = User::factory()->create();
    $tipo = TipoMedicion::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->delete(route('tipos-medicion.destroy', $tipo))
        ->assertSessionHas('exito');

    expect(TipoMedicion::find($tipo->id))->toBeNull();
});

it('a un extraño le contesta 403 antes de contarle si el tipo tiene datos', function (): void {
    $tipo = TipoMedicion::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('tipos-medicion.destroy', $tipo))
        ->assertForbidden();
});

it('una medición sigue mostrando su tipo aunque el tipo esté en la papelera', function (): void {
    /*
     * Se puede borrar un tipo cuyas mediciones estén todas en la papelera.
     * Si alguna se restaura, sin `withTrashed` la relación devolvería null
     * y la fila aparecería como un número sin nombre ni unidad.
     */
    $usuario = User::factory()->create();
    $tipo = TipoMedicion::factory()->for($usuario, 'usuario')->create(['nombre' => 'Peso']);
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $medicion = Medicion::factory()->for($paciente)->for($tipo, 'tipo')->create();

    $medicion->delete();
    $tipo->delete();
    $medicion->restore();

    expect($medicion->fresh()->tipo?->nombre)->toBe('Peso');
});
