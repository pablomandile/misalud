<?php

declare(strict_types=1);

use App\Models\AplicacionVacuna;
use App\Models\Medicamento;
use App\Models\Paciente;
use App\Models\Tratamiento;
use App\Models\User;
use App\Models\Vacuna;

/*
|--------------------------------------------------------------------------
| Elegir de la lista o, con «Otro», escribirlo
|--------------------------------------------------------------------------
|
| El medicamento de un tratamiento y la vacuna de una dosis se eligen de un
| <select> de verdad (antes era un <datalist>, que no mostraba las opciones
| comunes hasta empezar a escribir). La última opción, «Otro: lo escribo»,
| muestra un campo de texto. Ver `EligeDelCatalogoOEscribe`.
*/

/**
 * @return array{0: User, 1: Paciente}
 */
function fichaParaElegir(): array
{
    $usuario = User::factory()->create();

    return [$usuario, Paciente::factory()->for($usuario, 'usuario')->create()];
}

function anotarDosis(object $test, User $usuario, Paciente $paciente, array $datos): void
{
    $test->actingAs($usuario)
        ->post(route('pacientes.vacunas.store', $paciente), ['fecha' => '2026-09-15', ...$datos])
        ->assertSessionHasNoErrors();
}

it('la vacuna se elige de la lista común, sin copiarla antes', function (): void {
    [$usuario, $paciente] = fichaParaElegir();
    $comun = Vacuna::factory()->semilla()->create(['nombre' => 'Antigripal']);

    anotarDosis($this, $usuario, $paciente, ['vacuna_id' => (string) $comun->id]);

    expect(AplicacionVacuna::sole()->vacuna_id)->toBe($comun->id)
        ->and(Vacuna::count())->toBe(1);
});

it('con «Otro» se escribe una vacuna nueva y queda en la lista propia', function (): void {
    [$usuario, $paciente] = fichaParaElegir();

    anotarDosis($this, $usuario, $paciente, ['vacuna_id' => 'otro', 'vacuna_nombre' => ' Fiebre amarilla ']);

    $vacuna = AplicacionVacuna::sole()->vacuna;

    expect($vacuna->nombre)->toBe('Fiebre amarilla')
        ->and($vacuna->usuario_id)->toBe($usuario->id);
});

it('con «Otro», un nombre que ya está en la lista común usa esa en vez de crear otra', function (): void {
    [$usuario, $paciente] = fichaParaElegir();
    $comun = Vacuna::factory()->semilla()->create(['nombre' => 'Antigripal']);

    anotarDosis($this, $usuario, $paciente, ['vacuna_id' => 'otro', 'vacuna_nombre' => 'antigripal']);

    expect(AplicacionVacuna::sole()->vacuna_id)->toBe($comun->id)
        ->and(Vacuna::count())->toBe(1);
});

it('eligiendo de la lista, el nombre del campo oculto no cuenta', function (): void {
    [$usuario, $paciente] = fichaParaElegir();
    $comun = Vacuna::factory()->semilla()->create(['nombre' => 'Antigripal']);

    // El campo de texto oculto viaja igual, con lo que haya quedado escrito.
    anotarDosis($this, $usuario, $paciente, ['vacuna_id' => (string) $comun->id, 'vacuna_nombre' => 'Otra cosa']);

    expect(AplicacionVacuna::sole()->vacuna_id)->toBe($comun->id)
        ->and(Vacuna::count())->toBe(1);
});

it('sin elegir ni escribir, pide la vacuna', function (): void {
    [$usuario, $paciente] = fichaParaElegir();

    $this->actingAs($usuario)
        ->post(route('pacientes.vacunas.store', $paciente), ['fecha' => '2026-09-15', 'vacuna_id' => 'otro', 'vacuna_nombre' => ''])
        ->assertSessionHasErrors('vacuna_id');
});

it('el medicamento con «Otro» se crea en la lista propia', function (): void {
    [$usuario, $paciente] = fichaParaElegir();

    $this->actingAs($usuario)
        ->post(route('pacientes.tratamientos.store', $paciente), [
            'medicamento_id' => 'otro',
            'medicamento_nombre' => 'Remedio Nuevo',
            'dosis' => '1',
            'inicio' => '2026-09-01',
        ])
        ->assertSessionHasNoErrors();

    expect(Tratamiento::sole()->medicamento->nombre_comercial)->toBe('Remedio Nuevo');
});

it('el medicamento elegido de la lista común se usa tal cual', function (): void {
    [$usuario, $paciente] = fichaParaElegir();
    $comun = Medicamento::factory()->semilla()->create(['nombre_comercial' => 'Ibuprofeno']);

    $this->actingAs($usuario)
        ->post(route('pacientes.tratamientos.store', $paciente), [
            'medicamento_id' => (string) $comun->id,
            'medicamento_nombre' => '',
            'dosis' => '1',
            'inicio' => '2026-09-01',
        ])
        ->assertSessionHasNoErrors();

    expect(Tratamiento::sole()->medicamento_id)->toBe($comun->id)
        ->and(Medicamento::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Vacunas como Medicamentos
|--------------------------------------------------------------------------
*/

it('el catálogo de vacunas lista solo las propias: las comunes se eligen en el carnet', function (): void {
    [$usuario] = fichaParaElegir();
    Vacuna::factory()->semilla()->create(['nombre' => 'Antigripal']);
    Vacuna::factory()->for($usuario, 'usuario')->create(['nombre' => 'Mía']);

    $this->actingAs($usuario)
        ->get(route('vacunas.index'))
        ->assertInertia(fn ($p) => $p->has('registros', 1)->where('registros.0.nombre', 'Mía'));
});

it('el carnet ofrece las comunes en el select', function (): void {
    [$usuario, $paciente] = fichaParaElegir();
    Vacuna::factory()->semilla()->create(['nombre' => 'Antigripal']);

    $this->actingAs($usuario)
        ->get(route('pacientes.vacunas.index', $paciente))
        ->assertInertia(fn ($p) => $p->where('catalogoVacunas.0.nombre', 'Antigripal'));
});

it('«Vacunas» del menú abre el carnet de la ficha de la cuenta', function (): void {
    [$usuario, $paciente] = fichaParaElegir();

    $this->actingAs($usuario)
        ->get(route('vacunas-del-paciente'))
        ->assertRedirect(route('pacientes.vacunas.index', $paciente));
});
