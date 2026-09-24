<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Enums\SeveridadAlergia;
use App\Models\Alergia;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * @return array{0: User, 1: Paciente}
 */
function fichaConAlergias(): array
{
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    return [$usuario, $paciente];
}

it('carga una alergia en la ficha del paciente', function (): void {
    [$usuario, $paciente] = fichaConAlergias();

    $this->actingAs($usuario)
        ->post(route('pacientes.alergias.store', $paciente), [
            'sustancia' => 'Penicilina',
            'severidad' => SeveridadAlergia::Grave->value,
            'reaccion' => 'Urticaria',
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    $alergia = Alergia::first();

    expect($alergia->sustancia)->toBe('Penicilina')
        ->and($alergia->paciente_id)->toBe($paciente->id)
        ->and($alergia->severidad)->toBe(SeveridadAlergia::Grave);
});

it('NO deja elegir en la ficha de quién escribir', function (): void {
    [$usuario, $paciente] = fichaConAlergias();
    $ajeno = Paciente::factory()->create();

    $this->actingAs($usuario)->post(route('pacientes.alergias.store', $paciente), [
        'paciente_id' => $ajeno->id,
        'sustancia' => 'Polen',
        'severidad' => SeveridadAlergia::Leve->value,
    ]);

    expect(Alergia::first()->paciente_id)->toBe($paciente->id);
});

it('cifra la sustancia y la reacción', function (): void {
    $alergia = Alergia::factory()->create([
        'sustancia' => 'Penicilina Confidencial',
        'reaccion' => 'Erupción reservada',
    ]);

    $crudo = DB::table('alergias')->where('id', $alergia->id)->first();

    expect($crudo->sustancia)->not->toContain('Confidencial')
        ->and($crudo->reaccion)->not->toContain('reservada')
        // La severidad queda en claro: es un enum, no contenido.
        ->and($crudo->severidad)->toBe('moderada')
        ->and($alergia->fresh()->sustancia)->toBe('Penicilina Confidencial');
});

it('viaja como prop de la pantalla de enfermedades, sin index propio', function (): void {
    [$usuario, $paciente] = fichaConAlergias();
    Alergia::factory()->for($paciente)->create(['sustancia' => 'Penicilina']);

    $this->actingAs($usuario)
        ->get(route('pacientes.enfermedades.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->has('alergias', 1)
            ->where('alergias.0.sustancia', 'Penicilina')
            ->where('alergias.0.severidadEtiqueta', 'Moderada')
        );
});

it('exige la sustancia y una severidad válida', function (): void {
    [$usuario, $paciente] = fichaConAlergias();

    $this->actingAs($usuario)
        ->post(route('pacientes.alergias.store', $paciente), [
            'sustancia' => '',
            'severidad' => 'inventada',
        ])
        ->assertSessionHasErrors(['sustancia', 'severidad']);
});

it('no deja cargar dos veces la misma sustancia para un paciente', function (): void {
    /*
     * Dos "Penicilina" con severidades distintas no dejarían saber cuál
     * vale. Lo frena la validación por índice ciego, no la base con un 500:
     * `sustancia` está cifrada y un `Rule::unique` no detectaría nada.
     */
    [$usuario, $paciente] = fichaConAlergias();
    Alergia::factory()->for($paciente)->create(['sustancia' => 'Penicilina']);

    $this->actingAs($usuario)
        ->post(route('pacientes.alergias.store', $paciente), [
            'sustancia' => '  PENICILINA  ',
            'severidad' => SeveridadAlergia::Leve->value,
        ])
        ->assertSessionHasErrors('sustancia');

    expect(Alergia::count())->toBe(1);
});

it('la misma sustancia SÍ puede estar en otro paciente', function (): void {
    [$usuario, $paciente] = fichaConAlergias();
    $otro = Paciente::factory()->for($usuario, 'usuario')->create();
    Alergia::factory()->for($otro)->create(['sustancia' => 'Penicilina']);

    $this->actingAs($usuario)
        ->post(route('pacientes.alergias.store', $paciente), [
            'sustancia' => 'Penicilina',
            'severidad' => SeveridadAlergia::Leve->value,
        ])
        ->assertSessionHasNoErrors();

    expect(Alergia::count())->toBe(2);
});

it('editar sin cambiar la sustancia no choca contra sí misma', function (): void {
    [$usuario, $paciente] = fichaConAlergias();
    $alergia = Alergia::factory()->for($paciente)->create(['sustancia' => 'Polen']);

    $this->actingAs($usuario)
        ->put(route('alergias.update', $alergia), [
            'sustancia' => 'Polen',
            'severidad' => SeveridadAlergia::Grave->value,
        ])
        ->assertSessionHasNoErrors();

    expect($alergia->fresh()->severidad)->toBe(SeveridadAlergia::Grave);
});

it('un usuario ajeno no ve ni carga alergias', function (): void {
    [, $paciente] = fichaConAlergias();
    $ajeno = User::factory()->create();

    $this->actingAs($ajeno)
        ->post(route('pacientes.alergias.store', $paciente), [
            'sustancia' => 'Polen',
            'severidad' => SeveridadAlergia::Leve->value,
        ])
        ->assertForbidden();
});

it('un lector no puede cargar ni borrar', function (): void {
    [, $paciente] = fichaConAlergias();
    $alergia = Alergia::factory()->for($paciente)->create();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->post(route('pacientes.alergias.store', $paciente), [
            'sustancia' => 'Polen',
            'severidad' => SeveridadAlergia::Leve->value,
        ])
        ->assertForbidden();

    $this->actingAs($lector)
        ->delete(route('alergias.destroy', $alergia))
        ->assertForbidden();
});

it('el dueño borra su alergia', function (): void {
    [$usuario, $paciente] = fichaConAlergias();
    $alergia = Alergia::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->delete(route('alergias.destroy', $alergia))
        ->assertRedirect()
        ->assertSessionHas('exito');

    expect(Alergia::find($alergia->id))->toBeNull();
});
