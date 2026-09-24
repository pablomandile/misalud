<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Models\Enfermedad;
use App\Models\Medicamento;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Tratamiento;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * @return array{0: User, 1: Paciente, 2: Medicamento}
 */
function fichaConTratamientos(): array
{
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $medicamento = Medicamento::factory()->for($usuario, 'usuario')->create();

    return [$usuario, $paciente, $medicamento];
}

/*
|--------------------------------------------------------------------------
| Alta, listado y cifrado
|--------------------------------------------------------------------------
*/

it('carga un tratamiento en la ficha del paciente', function (): void {
    [$usuario, $paciente, $medicamento] = fichaConTratamientos();

    $this->actingAs($usuario)
        ->post(route('pacientes.tratamientos.store', $paciente), [
            'medicamento_id' => $medicamento->id,
            'dosis' => '500mg',
            'frecuencia' => 'Cada 8 horas',
            'inicio' => '2026-09-01',
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    $tratamiento = Tratamiento::first();

    expect($tratamiento->paciente_id)->toBe($paciente->id)
        ->and($tratamiento->medicamento_id)->toBe($medicamento->id)
        ->and($tratamiento->dosis)->toBe('500mg')
        ->and($tratamiento->activo)->toBeTrue();
});

it('NO deja elegir en la ficha de quién escribir', function (): void {
    [$usuario, $paciente, $medicamento] = fichaConTratamientos();
    $ajeno = Paciente::factory()->create();

    $this->actingAs($usuario)->post(route('pacientes.tratamientos.store', $paciente), [
        'paciente_id' => $ajeno->id,
        'medicamento_id' => $medicamento->id,
        'dosis' => '500mg',
        'frecuencia' => 'Diario',
        'inicio' => '2026-09-01',
    ]);

    expect(Tratamiento::first()->paciente_id)->toBe($paciente->id);
});

it('cifra la dosis, la frecuencia y las notas en la base', function (): void {
    $tratamiento = Tratamiento::factory()->create([
        'dosis' => '500mg Confidencial',
        'frecuencia' => 'Cada 8 horas Confidencial',
        'notas' => 'Detalle reservado',
    ]);

    $crudo = DB::table('tratamientos')->where('id', $tratamiento->id)->first();

    expect($crudo->dosis)->not->toContain('Confidencial')
        ->and($crudo->frecuencia)->not->toContain('Confidencial')
        ->and($crudo->notas)->not->toContain('reservado')
        // activo, inicio y fin quedan en claro: son por donde se ordena.
        ->and((bool) $crudo->activo)->toBeTrue()
        ->and($tratamiento->fresh()->dosis)->toBe('500mg Confidencial');
});

it('separa lo activo de lo inactivo', function (): void {
    [$usuario, $paciente, $medicamento] = fichaConTratamientos();
    Tratamiento::factory()->for($paciente)->for($medicamento)->create();
    Tratamiento::factory()->for($paciente)->for($medicamento)->inactivo()->create();

    $this->actingAs($usuario)
        ->get(route('pacientes.tratamientos.index', $paciente))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('tratamientos/Index')
            ->has('tratamientos', 2)
            ->where('tratamientos.0.activo', true)
            ->where('tratamientos.1.activo', false)
        );
});

it('un checkbox destildado desactiva el tratamiento', function (): void {
    // Un checkbox sin tildar no manda el campo: llega ausente, no "false".
    [$usuario, $paciente, $medicamento] = fichaConTratamientos();
    $tratamiento = Tratamiento::factory()->for($paciente)->for($medicamento)->create(['activo' => true]);

    $this->actingAs($usuario)
        ->put(route('tratamientos.update', $tratamiento), [
            'medicamento_id' => $medicamento->id,
            'dosis' => $tratamiento->dosis,
            'frecuencia' => $tratamiento->frecuencia,
            'inicio' => $tratamiento->inicio->format('Y-m-d'),
            // 'activo' ausente.
        ])
        ->assertSessionHasNoErrors();

    expect($tratamiento->fresh()->activo)->toBeFalse();
});

it('exige medicamento, dosis, frecuencia e inicio', function (): void {
    [$usuario, $paciente] = fichaConTratamientos();

    $this->actingAs($usuario)
        ->post(route('pacientes.tratamientos.store', $paciente), [])
        ->assertSessionHasErrors(['medicamento_id', 'dosis', 'frecuencia', 'inicio']);
});

it('rechaza un fin anterior al inicio', function (): void {
    [$usuario, $paciente, $medicamento] = fichaConTratamientos();

    $this->actingAs($usuario)
        ->post(route('pacientes.tratamientos.store', $paciente), [
            'medicamento_id' => $medicamento->id,
            'dosis' => '500mg',
            'frecuencia' => 'Diario',
            'inicio' => '2026-09-20',
            'fin' => '2026-09-10',
        ])
        ->assertSessionHasErrors('fin');
});

it('acepta un inicio futuro: el médico lo puede indicar para después', function (): void {
    // Al revés que una fecha de diagnóstico, un tratamiento no tiene por
    // qué ser del pasado: puede empezar mañana.
    [$usuario, $paciente, $medicamento] = fichaConTratamientos();

    $this->actingAs($usuario)
        ->post(route('pacientes.tratamientos.store', $paciente), [
            'medicamento_id' => $medicamento->id,
            'dosis' => '500mg',
            'frecuencia' => 'Diario',
            'inicio' => now()->addWeek()->format('Y-m-d'),
        ])
        ->assertSessionHasNoErrors();

    expect(Tratamiento::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| El medicamento: mismo criterio que médicos y enfermedades
|--------------------------------------------------------------------------
*/

it('NO deja usar el medicamento de otro usuario', function (): void {
    [$usuario, $paciente] = fichaConTratamientos();
    $ajeno = Medicamento::factory()->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.tratamientos.store', $paciente), [
            'medicamento_id' => $ajeno->id,
            'dosis' => '500mg',
            'frecuencia' => 'Diario',
            'inicio' => '2026-09-01',
        ])
        ->assertSessionHasErrors('medicamento_id');

    expect(Tratamiento::count())->toBe(0);
});

it('SÍ deja usar una semilla compartida', function (): void {
    [$usuario, $paciente] = fichaConTratamientos();
    $semilla = Medicamento::factory()->semilla()->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.tratamientos.store', $paciente), [
            'medicamento_id' => $semilla->id,
            'dosis' => '500mg',
            'frecuencia' => 'Diario',
            'inicio' => '2026-09-01',
        ])
        ->assertSessionHasNoErrors();

    expect(Tratamiento::count())->toBe(1);
});

it('un cuidador puede editar un tratamiento con el medicamento del dueño', function (): void {
    [$duenio, $paciente] = fichaConTratamientos();
    $medicamentoDelDuenio = Medicamento::factory()->for($duenio, 'usuario')->create();
    $tratamiento = Tratamiento::factory()->for($paciente)->for($medicamentoDelDuenio)->create();

    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->actingAs($cuidador)
        ->put(route('tratamientos.update', $tratamiento), [
            'medicamento_id' => $medicamentoDelDuenio->id,
            'dosis' => $tratamiento->dosis,
            'frecuencia' => $tratamiento->frecuencia,
            'inicio' => $tratamiento->inicio->format('Y-m-d'),
            'notas' => 'Agregado por el cuidador',
        ])
        ->assertSessionHasNoErrors();

    expect($tratamiento->fresh()->notas)->toBe('Agregado por el cuidador');
});

/*
|--------------------------------------------------------------------------
| El médico y la enfermedad: opcionales, mismas reglas de siempre
|--------------------------------------------------------------------------
*/

it('NO deja usar el médico de otro usuario', function (): void {
    [$usuario, $paciente, $medicamento] = fichaConTratamientos();
    $ajeno = Medico::factory()->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.tratamientos.store', $paciente), [
            'medicamento_id' => $medicamento->id,
            'medico_id' => $ajeno->id,
            'dosis' => '500mg',
            'frecuencia' => 'Diario',
            'inicio' => '2026-09-01',
        ])
        ->assertSessionHasErrors('medico_id');
});

it('NO deja vincular la enfermedad de otro paciente', function (): void {
    [$usuario, $paciente, $medicamento] = fichaConTratamientos();
    $deOtro = Enfermedad::factory()->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.tratamientos.store', $paciente), [
            'medicamento_id' => $medicamento->id,
            'enfermedad_id' => $deOtro->id,
            'dosis' => '500mg',
            'frecuencia' => 'Diario',
            'inicio' => '2026-09-01',
        ])
        ->assertSessionHasErrors('enfermedad_id');
});

it('SÍ deja vincular una enfermedad del mismo paciente', function (): void {
    [$usuario, $paciente, $medicamento] = fichaConTratamientos();
    $enfermedad = Enfermedad::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.tratamientos.store', $paciente), [
            'medicamento_id' => $medicamento->id,
            'enfermedad_id' => $enfermedad->id,
            'dosis' => '500mg',
            'frecuencia' => 'Diario',
            'inicio' => '2026-09-01',
        ])
        ->assertSessionHasNoErrors();

    expect(Tratamiento::first()->enfermedad_id)->toBe($enfermedad->id);
});

/*
|--------------------------------------------------------------------------
| Un medicamento con tratamientos no se puede borrar del catálogo
|--------------------------------------------------------------------------
*/

it('NO deja borrar un medicamento con tratamientos cargados', function (): void {
    [$usuario, $paciente, $medicamento] = fichaConTratamientos();
    Tratamiento::factory()->for($paciente)->for($medicamento)->create();

    $respuesta = $this->actingAs($usuario)->delete(route('medicamentos.destroy', $medicamento));

    expect($respuesta->getSession()->get('error'))->toContain('ya tiene tratamientos')
        ->and(Medicamento::find($medicamento->id))->not->toBeNull();
});

it('sí deja borrar un medicamento que nadie usó en un tratamiento', function (): void {
    [$usuario, , $medicamento] = fichaConTratamientos();

    $this->actingAs($usuario)
        ->delete(route('medicamentos.destroy', $medicamento))
        ->assertSessionHas('exito');

    expect(Medicamento::find($medicamento->id))->toBeNull();
});

it('sigue mostrando el medicamento aunque esté en la papelera', function (): void {
    [$usuario, $paciente, $medicamento] = fichaConTratamientos();
    $tratamiento = Tratamiento::factory()->for($paciente)->for($medicamento)->create();

    // Vaciar los tratamientos para poder borrar el medicamento del catálogo.
    $tratamiento->delete();
    $this->actingAs($usuario)->delete(route('medicamentos.destroy', $medicamento));
    $tratamiento->restore();

    expect($tratamiento->fresh()->medicamento?->nombre_comercial)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Autorización
|--------------------------------------------------------------------------
*/

it('un usuario ajeno no ve ni carga tratamientos', function (): void {
    [, $paciente, $medicamento] = fichaConTratamientos();
    $ajeno = User::factory()->create();

    $this->actingAs($ajeno)
        ->get(route('pacientes.tratamientos.index', $paciente))
        ->assertForbidden();

    $this->actingAs($ajeno)
        ->post(route('pacientes.tratamientos.store', $paciente), [
            'medicamento_id' => $medicamento->id,
            'dosis' => '500mg',
            'frecuencia' => 'Diario',
            'inicio' => '2026-09-01',
        ])
        ->assertForbidden();
});

it('un lector ve pero no carga ni borra', function (): void {
    [, $paciente, $medicamento] = fichaConTratamientos();
    $tratamiento = Tratamiento::factory()->for($paciente)->for($medicamento)->create();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->get(route('pacientes.tratamientos.index', $paciente))
        ->assertOk();

    $this->actingAs($lector)
        ->post(route('pacientes.tratamientos.store', $paciente), [
            'medicamento_id' => $medicamento->id,
            'dosis' => '500mg',
            'frecuencia' => 'Diario',
            'inicio' => '2026-09-01',
        ])
        ->assertForbidden();

    $this->actingAs($lector)
        ->delete(route('tratamientos.destroy', $tratamiento))
        ->assertForbidden();
});

it('el dueño borra su tratamiento', function (): void {
    [$usuario, $paciente, $medicamento] = fichaConTratamientos();
    $tratamiento = Tratamiento::factory()->for($paciente)->for($medicamento)->create();

    $this->actingAs($usuario)
        ->delete(route('tratamientos.destroy', $tratamiento))
        ->assertRedirect()
        ->assertSessionHas('exito');

    expect(Tratamiento::find($tratamiento->id))->toBeNull();
});

it('exige sesión', function (): void {
    [, $paciente] = fichaConTratamientos();

    $this->get(route('pacientes.tratamientos.index', $paciente))->assertRedirect(route('login'));
});
