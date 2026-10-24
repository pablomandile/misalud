<?php

declare(strict_types=1);

use App\Enums\PeriodicidadToma;
use App\Enums\RolPaciente;
use App\Models\AplicacionMedicamento;
use App\Models\Enfermedad;
use App\Models\Paciente;
use App\Models\Tratamiento;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| La sección «Tratamiento»: cada aplicación de un medicamento no diario
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-10 12:00:00');
});

/**
 * @return array{0: User, 1: Paciente, 2: Tratamiento, 3: Enfermedad}
 */
function fichaConTratamiento(PeriodicidadToma $toma = PeriodicidadToma::Mensual): array
{
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $enfermedad = Enfermedad::factory()->create(['paciente_id' => $paciente->id, 'nombre' => 'Osteoporosis']);
    $tratamiento = Tratamiento::factory()->create([
        'paciente_id' => $paciente->id,
        'periodicidad' => $toma,
        'enfermedad_id' => $enfermedad->id,
    ]);

    return [$usuario, $paciente, $tratamiento, $enfermedad];
}

it('anota una aplicación con su enfermedad, y la nota va cifrada', function (): void {
    [$usuario, $paciente, $tratamiento, $enfermedad] = fichaConTratamiento();

    $this->actingAs($usuario)
        ->post(route('pacientes.aplicaciones-medicamento.store', $paciente), [
            'tratamiento_id' => $tratamiento->id,
            'enfermedad_id' => $enfermedad->id,
            'fecha' => '2026-10-01',
            'notas' => 'En el brazo izquierdo',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('exito');

    $aplicacion = AplicacionMedicamento::sole();

    expect($aplicacion->paciente_id)->toBe($paciente->id)
        ->and($aplicacion->fecha->format('Y-m-d'))->toBe('2026-10-01')
        ->and($aplicacion->notas)->toBe('En el brazo izquierdo')
        ->and(DB::table('aplicaciones_medicamento')->value('notas'))->not->toContain('brazo');
});

it('no acepta un medicamento de todos los días, ni uno o una enfermedad de otra ficha', function (): void {
    [$usuario, $paciente] = fichaConTratamiento();
    $diario = Tratamiento::factory()->create(['paciente_id' => $paciente->id, 'periodicidad' => PeriodicidadToma::Diaria]);
    [, , $ajeno, $enfermedadAjena] = fichaConTratamiento();

    $this->actingAs($usuario)
        ->post(route('pacientes.aplicaciones-medicamento.store', $paciente), [
            'tratamiento_id' => $diario->id,
            'fecha' => '2026-10-01',
        ])
        ->assertSessionHasErrors('tratamiento_id');

    $this->actingAs($usuario)
        ->post(route('pacientes.aplicaciones-medicamento.store', $paciente), [
            'tratamiento_id' => $ajeno->id,
            'enfermedad_id' => $enfermedadAjena->id,
            'fecha' => '2026-10-01',
        ])
        ->assertSessionHasErrors(['tratamiento_id', 'enfermedad_id']);

    expect(AplicacionMedicamento::count())->toBe(0);
});

it('no acepta una fecha futura', function (): void {
    [$usuario, $paciente, $tratamiento] = fichaConTratamiento();

    $this->actingAs($usuario)
        ->post(route('pacientes.aplicaciones-medicamento.store', $paciente), [
            'tratamiento_id' => $tratamiento->id,
            'fecha' => '2026-10-20',
        ])
        ->assertSessionHasErrors('fecha');
});

it('un lector ve el historial pero no anota', function (): void {
    [, $paciente, $tratamiento] = fichaConTratamiento();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->get(route('pacientes.aplicaciones-medicamento.index', $paciente))
        ->assertOk();

    $this->actingAs($lector)
        ->post(route('pacientes.aplicaciones-medicamento.store', $paciente), [
            'tratamiento_id' => $tratamiento->id,
            'fecha' => '2026-10-01',
        ])
        ->assertForbidden();
});

it('agrupa por enfermedad, la más reciente arriba y sin enfermedad al final, y ofrece solo los no diarios', function (): void {
    [$usuario, $paciente, $tratamiento, $enfermedad] = fichaConTratamiento();
    Tratamiento::factory()->create(['paciente_id' => $paciente->id, 'periodicidad' => PeriodicidadToma::Diaria]);
    $otra = Enfermedad::factory()->create(['paciente_id' => $paciente->id, 'nombre' => 'Asma']);

    $crear = fn (?int $enfermedadId, string $fecha) => AplicacionMedicamento::factory()->create([
        'paciente_id' => $paciente->id,
        'tratamiento_id' => $tratamiento->id,
        'enfermedad_id' => $enfermedadId,
        'fecha' => $fecha,
    ]);
    $crear($enfermedad->id, '2026-08-01');
    $crear($enfermedad->id, '2026-09-01');
    $crear($otra->id, '2026-07-01');
    $crear(null, '2026-10-01');

    $this->actingAs($usuario)
        ->get(route('pacientes.aplicaciones-medicamento.index', $paciente))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('aplicaciones-medicamento/Index')
            ->where('grupos.0.nombre', 'Osteoporosis')
            ->where('grupos.0.aplicaciones.0.fecha', '2026-09-01')
            ->where('grupos.1.nombre', 'Asma')
            ->where('grupos.2.nombre', 'Sin enfermedad')
            ->has('medicamentos', 1)
            ->where('medicamentos.0.enfermedadId', $enfermedad->id));
});

it('edita y borra una aplicación', function (): void {
    [$usuario, $paciente, $tratamiento] = fichaConTratamiento();
    $aplicacion = AplicacionMedicamento::factory()->create([
        'paciente_id' => $paciente->id,
        'tratamiento_id' => $tratamiento->id,
    ]);

    $this->actingAs($usuario)
        ->put(route('aplicaciones-medicamento.update', $aplicacion), [
            'tratamiento_id' => $tratamiento->id,
            'fecha' => '2026-10-05',
        ])
        ->assertSessionHasNoErrors();

    expect($aplicacion->refresh()->fecha->format('Y-m-d'))->toBe('2026-10-05');

    $this->actingAs($usuario)
        ->delete(route('aplicaciones-medicamento.destroy', $aplicacion))
        ->assertSessionHas('exito');

    expect(AplicacionMedicamento::count())->toBe(0);
});
