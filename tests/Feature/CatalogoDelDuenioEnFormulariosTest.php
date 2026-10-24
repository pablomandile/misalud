<?php

declare(strict_types=1);

use App\Enums\EstadoEnfermedad;
use App\Enums\RolPaciente;
use App\Models\Enfermedad;
use App\Models\Medicamento;
use App\Models\Medicion;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\TipoMedicion;
use App\Models\Tratamiento;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Los formularios de una ficha compartida ofrecen la lista del dueño
|--------------------------------------------------------------------------
|
| Bug medido en producción: un cuidador abría "Médico" en Enfermedades y
| encontraba solo "Sin especificar". Los médicos son de la cuenta del dueño,
| y el formulario ofrecía lo propio del cuidador (nada) más lo que la ficha ya
| usaba. Compartir la ficha comparte la lista del dueño (CatalogoDeLaFicha):
| el formulario y su validación ahora también.
*/

/**
 * @return array{0: User, 1: User, 2: Paciente}
 */
function fichaDeOtroComoCuidador(): array
{
    $duenio = User::factory()->create();
    $cuidador = User::factory()->create();
    $ficha = Paciente::factory()->for($duenio, 'usuario')->create();
    $ficha->cuidadores()->attach($cuidador->id, ['rol' => RolPaciente::Cuidador->value]);

    return [$duenio, $cuidador, $ficha];
}

/**
 * @return list<string>
 */
function nombresDeMedicosOfrecidos(object $test, User $usuario, Paciente $ficha): array
{
    $medicos = [];

    $test->actingAs($usuario)
        ->get(route('pacientes.enfermedades.index', $ficha))
        ->assertOk()
        ->assertInertia(function ($pagina) use (&$medicos): void {
            $medicos = array_column($pagina->toArray()['props']['medicos'], 'nombre');
        });

    return $medicos;
}

it('al cuidador le ofrece los médicos del dueño, aunque la ficha todavía no los use', function (): void {
    [$duenio, $cuidador, $ficha] = fichaDeOtroComoCuidador();
    Medico::factory()->for($duenio, 'usuario')->create(['nombre' => 'Dra. López']);
    Medico::factory()->for(User::factory(), 'usuario')->create(['nombre' => 'Dr. De Un Extraño']);

    expect(nombresDeMedicosOfrecidos($this, $cuidador, $ficha))->toBe(['Dra. López']);
});

it('el cuidador puede guardar una enfermedad con el médico del dueño', function (): void {
    [$duenio, $cuidador, $ficha] = fichaDeOtroComoCuidador();
    $medico = Medico::factory()->for($duenio, 'usuario')->create();

    $this->actingAs($cuidador)
        ->post(route('pacientes.enfermedades.store', $ficha), [
            'nombre' => 'Hipertensión',
            'estado' => EstadoEnfermedad::Cronica->value,
            'medico_id' => $medico->id,
        ])
        ->assertSessionHasNoErrors();

    expect(Enfermedad::sole()->medico_id)->toBe($medico->id);
});

it('el médico de un extraño se sigue rechazando', function (): void {
    [, $cuidador, $ficha] = fichaDeOtroComoCuidador();
    $ajeno = Medico::factory()->for(User::factory(), 'usuario')->create();

    $this->actingAs($cuidador)
        ->post(route('pacientes.enfermedades.store', $ficha), [
            'nombre' => 'Hipertensión',
            'estado' => EstadoEnfermedad::Cronica->value,
            'medico_id' => $ajeno->id,
        ])
        ->assertSessionHasErrors('medico_id');
});

it('en una ficha propia NO se cuela la lista de quien me compartió otra', function (): void {
    [$duenio, $cuidador] = fichaDeOtroComoCuidador();
    Medico::factory()->for($duenio, 'usuario')->create(['nombre' => 'Dra. Del Otro Dueño']);
    Medico::factory()->for($cuidador, 'usuario')->create(['nombre' => 'Dr. Mío']);
    $propia = Paciente::factory()->for($cuidador, 'usuario')->create();

    expect(nombresDeMedicosOfrecidos($this, $cuidador, $propia))->toBe(['Dr. Mío']);
});

it('el medicamento escrito por nombre usa el del dueño en vez de crear otro', function (): void {
    [$duenio, $cuidador, $ficha] = fichaDeOtroComoCuidador();
    $delDuenio = Medicamento::factory()->for($duenio, 'usuario')->create(['nombre_comercial' => 'Losartán']);

    $this->actingAs($cuidador)
        ->post(route('pacientes.tratamientos.store', $ficha), [
            'medicamento_nombre' => 'losartán',
            'dosis' => '50 mg',
            'inicio' => '2026-09-01',
        ])
        ->assertSessionHasNoErrors();

    expect(Tratamiento::sole()->medicamento_id)->toBe($delDuenio->id)
        ->and(Medicamento::count())->toBe(1);
});

it('el cuidador puede cargar una medición en una variable del dueño', function (): void {
    [$duenio, $cuidador, $ficha] = fichaDeOtroComoCuidador();
    $tipo = TipoMedicion::factory()->for($duenio, 'usuario')->create();

    $this->actingAs($cuidador)
        ->post(route('pacientes.mediciones.store', $ficha), [
            'tipo_medicion_id' => $tipo->id,
            'fecha' => '2026-09-20T08:00',
            'valor' => '72',
        ])
        ->assertSessionHasNoErrors();

    expect(Medicion::sole()->tipo_medicion_id)->toBe($tipo->id);
});
