<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Enums\TipoRecordatorio;
use App\Models\AplicacionVacuna;
use App\Models\Centro;
use App\Models\Paciente;
use App\Models\Recordatorio;
use App\Models\User;
use App\Models\Vacuna;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-01 12:00:00');
});

/**
 * @return array{0: User, 1: Paciente, 2: Vacuna}
 */
function fichaConCarnet(): array
{
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $vacuna = Vacuna::factory()->for($usuario, 'usuario')->create(['nombre' => 'Hepatitis B']);

    return [$usuario, $paciente, $vacuna];
}

function avisosDeVacuna(): Collection
{
    return Recordatorio::query()->where('tipo', TipoRecordatorio::VacunaProxima->value)->get();
}

/*
|--------------------------------------------------------------------------
| Alta y cifrado
|--------------------------------------------------------------------------
*/

it('anota una dosis en el carnet del paciente', function (): void {
    [$usuario, $paciente, $vacuna] = fichaConCarnet();

    $this->actingAs($usuario)
        ->post(route('pacientes.vacunas.store', $paciente), [
            'vacuna_id' => $vacuna->id,
            'fecha' => '2026-09-15',
            'dosis' => '1ª dosis',
            'lote' => 'AB123',
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    $dosis = AplicacionVacuna::sole();

    expect($dosis->paciente_id)->toBe($paciente->id)
        ->and($dosis->vacuna_id)->toBe($vacuna->id)
        ->and($dosis->fecha->format('Y-m-d'))->toBe('2026-09-15')
        ->and($dosis->lote)->toBe('AB123');
});

it('guarda cifrados la dosis, el lote y las notas', function (): void {
    [, $paciente, $vacuna] = fichaConCarnet();
    AplicacionVacuna::factory()->for($paciente)->create([
        'vacuna_id' => $vacuna->id,
        'dosis' => 'Refuerzo',
        'lote' => 'LOTE-SECRETO',
        'notas' => 'Nota clínica',
    ]);

    $fila = DB::table('aplicaciones_vacuna')->first();

    expect($fila->lote)->not->toContain('LOTE-SECRETO')
        ->and($fila->dosis)->not->toContain('Refuerzo')
        ->and($fila->notas)->not->toContain('Nota clínica');
});

it('NO deja elegir en la ficha de quién escribir', function (): void {
    [$usuario, $paciente, $vacuna] = fichaConCarnet();
    $ajeno = Paciente::factory()->create();

    $this->actingAs($usuario)->post(route('pacientes.vacunas.store', $paciente), [
        'paciente_id' => $ajeno->id,
        'vacuna_id' => $vacuna->id,
        'fecha' => '2026-09-15',
    ]);

    expect(AplicacionVacuna::sole()->paciente_id)->toBe($paciente->id);
});

/*
|--------------------------------------------------------------------------
| Validación
|--------------------------------------------------------------------------
*/

it('no acepta una dosis aplicada en el futuro', function (): void {
    [$usuario, $paciente, $vacuna] = fichaConCarnet();

    $this->actingAs($usuario)
        ->post(route('pacientes.vacunas.store', $paciente), [
            'vacuna_id' => $vacuna->id,
            'fecha' => '2026-10-05',
        ])
        ->assertSessionHasErrors('fecha');

    expect(AplicacionVacuna::count())->toBe(0);
});

it('la próxima dosis tiene que ser posterior a la que se carga', function (): void {
    [$usuario, $paciente, $vacuna] = fichaConCarnet();

    $this->actingAs($usuario)
        ->post(route('pacientes.vacunas.store', $paciente), [
            'vacuna_id' => $vacuna->id,
            'fecha' => '2026-09-15',
            'proxima_dosis' => '2026-09-01',
        ])
        ->assertSessionHasErrors('proxima_dosis');
});

it('no deja usar la vacuna ni el centro del catálogo de otro', function (): void {
    [$usuario, $paciente] = fichaConCarnet();
    $otro = User::factory()->create();
    $vacunaAjena = Vacuna::factory()->for($otro, 'usuario')->create();
    $centroAjeno = Centro::factory()->for($otro, 'usuario')->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.vacunas.store', $paciente), [
            'vacuna_id' => $vacunaAjena->id,
            'centro_id' => $centroAjeno->id,
            'fecha' => '2026-09-15',
        ])
        ->assertSessionHasErrors(['vacuna_id', 'centro_id']);
});

it('un cuidador puede anotar una dosis con la vacuna que la ficha ya usa, aunque sea del dueño', function (): void {
    [$duenio, $paciente, $vacuna] = fichaConCarnet();
    AplicacionVacuna::factory()->for($paciente)->create(['vacuna_id' => $vacuna->id]);
    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->actingAs($cuidador)
        ->post(route('pacientes.vacunas.store', $paciente), [
            'vacuna_id' => $vacuna->id,
            'fecha' => '2026-09-20',
        ])
        ->assertSessionHasNoErrors();

    expect(AplicacionVacuna::count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| El recordatorio de la próxima dosis
|--------------------------------------------------------------------------
*/

it('una dosis con próxima fecha avisa el día anterior', function (): void {
    [, $paciente, $vacuna] = fichaConCarnet();

    AplicacionVacuna::factory()->for($paciente)->create([
        'vacuna_id' => $vacuna->id,
        'fecha' => '2026-09-15',
        'proxima_dosis' => '2026-10-15',
    ]);

    $aviso = avisosDeVacuna()->sole();

    expect($aviso->paciente_id)->toBe($paciente->id)
        ->and($aviso->instanteDelEvento()->format('Y-m-d'))->toBe('2026-10-15');
});

it('sin próxima dosis no hay nada que avisar', function (): void {
    [, $paciente, $vacuna] = fichaConCarnet();

    AplicacionVacuna::factory()->for($paciente)->create(['vacuna_id' => $vacuna->id]);

    expect(avisosDeVacuna())->toBeEmpty();
});

it('anotar la dosis siguiente apaga el aviso de la anterior, y borrarla lo vuelve a prender', function (): void {
    [, $paciente, $vacuna] = fichaConCarnet();

    $primera = AplicacionVacuna::factory()->for($paciente)->create([
        'vacuna_id' => $vacuna->id,
        'fecha' => '2026-08-01',
        'proxima_dosis' => '2026-09-01',
    ]);
    expect(avisosDeVacuna())->toHaveCount(1);

    $segunda = AplicacionVacuna::factory()->for($paciente)->create([
        'vacuna_id' => $vacuna->id,
        'fecha' => '2026-09-02',
        'proxima_dosis' => '2027-03-02',
    ]);

    // Queda uno solo, y es el de la segunda: la próxima de la primera ya se aplicó.
    $aviso = avisosDeVacuna()->sole();
    expect($aviso->origen_id)->toBe($segunda->id);

    $segunda->delete();

    expect(avisosDeVacuna()->sole()->origen_id)->toBe($primera->id);
});

it('la dosis de OTRA vacuna no apaga el aviso', function (): void {
    [$usuario, $paciente, $vacuna] = fichaConCarnet();
    $antigripal = Vacuna::factory()->for($usuario, 'usuario')->create(['nombre' => 'Antigripal']);

    AplicacionVacuna::factory()->for($paciente)->create([
        'vacuna_id' => $vacuna->id,
        'fecha' => '2026-08-01',
        'proxima_dosis' => '2026-11-01',
    ]);
    AplicacionVacuna::factory()->for($paciente)->create([
        'vacuna_id' => $antigripal->id,
        'fecha' => '2026-09-01',
    ]);

    expect(avisosDeVacuna())->toHaveCount(1);
});

it('dos dosis el mismo día no se tapan entre sí: avisa la cargada última', function (): void {
    [, $paciente, $vacuna] = fichaConCarnet();

    AplicacionVacuna::factory()->for($paciente)->create([
        'vacuna_id' => $vacuna->id,
        'fecha' => '2026-09-01',
        'proxima_dosis' => '2026-12-01',
    ]);
    $segunda = AplicacionVacuna::factory()->for($paciente)->create([
        'vacuna_id' => $vacuna->id,
        'fecha' => '2026-09-01',
        'proxima_dosis' => '2027-01-01',
    ]);

    expect(avisosDeVacuna()->sole()->origen_id)->toBe($segunda->id);
});

/*
|--------------------------------------------------------------------------
| Pantalla
|--------------------------------------------------------------------------
*/

it('agrupa por vacuna y toma la próxima dosis de la última aplicación', function (): void {
    [$usuario, $paciente, $vacuna] = fichaConCarnet();

    AplicacionVacuna::factory()->for($paciente)->create([
        'vacuna_id' => $vacuna->id,
        'fecha' => '2026-08-01',
        'proxima_dosis' => '2026-09-01',
    ]);
    AplicacionVacuna::factory()->for($paciente)->create([
        'vacuna_id' => $vacuna->id,
        'fecha' => '2026-09-02',
        'proxima_dosis' => '2027-03-02',
    ]);

    $this->actingAs($usuario)
        ->get(route('pacientes.vacunas.index', $paciente))
        ->assertInertia(fn ($pagina) => $pagina
            ->component('vacunas-aplicadas/Index')
            ->has('vacunas', 1)
            ->where('vacunas.0.nombre', 'Hepatitis B')
            ->where('vacunas.0.proximaDosis', '2027-03-02')
            ->has('vacunas.0.dosis', 2)
            ->where('vacunas.0.dosis.0.fecha', '2026-09-02'));
});

it('un lector ve el carnet pero no puede tocarlo', function (): void {
    [, $paciente, $vacuna] = fichaConCarnet();
    $dosis = AplicacionVacuna::factory()->for($paciente)->create(['vacuna_id' => $vacuna->id]);
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->get(route('pacientes.vacunas.index', $paciente))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->where('paciente.puedeEditar', false));

    $this->actingAs($lector)
        ->delete(route('vacunas-aplicadas.destroy', $dosis))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| El catálogo
|--------------------------------------------------------------------------
*/

it('una vacuna con dosis anotadas no se puede borrar del catálogo', function (): void {
    [$usuario, $paciente, $vacuna] = fichaConCarnet();
    AplicacionVacuna::factory()->for($paciente)->create(['vacuna_id' => $vacuna->id]);

    $this->actingAs($usuario)
        ->delete(route('vacunas.destroy', $vacuna))
        ->assertSessionHas('error');

    expect(Vacuna::find($vacuna->id))->not->toBeNull();
});
