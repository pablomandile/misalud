<?php

declare(strict_types=1);

use App\Enums\EstadoEnfermedad;
use App\Enums\RolPaciente;
use App\Models\Enfermedad;
use App\Models\Medicion;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\RegistroEnfermedad;
use App\Models\TipoMedicion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * @return array{0: User, 1: Paciente}
 */
function fichaConEnfermedades(): array
{
    $usuario = User::factory()->create(['zona_horaria' => 'America/Argentina/Buenos_Aires']);
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    return [$usuario, $paciente];
}

/*
|--------------------------------------------------------------------------
| Alta, listado y cifrado
|--------------------------------------------------------------------------
*/

it('carga una enfermedad en la ficha del paciente', function (): void {
    [$usuario, $paciente] = fichaConEnfermedades();

    $this->actingAs($usuario)
        ->post(route('pacientes.enfermedades.store', $paciente), [
            'nombre' => 'Hipertensión',
            'estado' => EstadoEnfermedad::Cronica->value,
            'fecha_diagnostico' => '2020-03-15',
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    $enfermedad = Enfermedad::first();

    expect($enfermedad->nombre)->toBe('Hipertensión')
        ->and($enfermedad->paciente_id)->toBe($paciente->id)
        ->and($enfermedad->estado)->toBe(EstadoEnfermedad::Cronica);
});

it('NO deja elegir en la ficha de quién escribir', function (): void {
    [$usuario, $paciente] = fichaConEnfermedades();
    $ajeno = Paciente::factory()->create();

    $this->actingAs($usuario)->post(route('pacientes.enfermedades.store', $paciente), [
        'paciente_id' => $ajeno->id,
        'nombre' => 'Hipertensión',
        'estado' => EstadoEnfermedad::Activa->value,
    ]);

    expect(Enfermedad::first()->paciente_id)->toBe($paciente->id);
});

it('cifra el nombre y las notas en la base', function (): void {
    $enfermedad = Enfermedad::factory()->create([
        'nombre' => 'Diabetes Confidencial',
        'notas' => 'Tratamiento reservado',
    ]);

    $crudo = DB::table('enfermedades')->where('id', $enfermedad->id)->first();

    expect($crudo->nombre)->not->toContain('Confidencial')
        ->and($crudo->notas)->not->toContain('reservado')
        // El estado y la fecha quedan en claro: son por donde se ordena.
        ->and($crudo->estado)->toBe('activa')
        ->and($enfermedad->fresh()->nombre)->toBe('Diabetes Confidencial');
});

it('separa lo vigente de lo resuelto', function (): void {
    [$usuario, $paciente] = fichaConEnfermedades();
    Enfermedad::factory()->for($paciente)->cronica()->create(['nombre' => 'Hipertensión']);
    Enfermedad::factory()->for($paciente)->resuelta()->create(['nombre' => 'Neumonía']);

    $this->actingAs($usuario)
        ->get(route('pacientes.enfermedades.index', $paciente))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('enfermedades/Index')
            ->has('enfermedades', 2)
            // Primero lo vigente: una crónica es del presente.
            ->where('enfermedades.0.nombre', 'Hipertensión')
            ->where('enfermedades.0.estaVigente', true)
            ->where('enfermedades.1.estaVigente', false)
        );
});

it('una crónica cuenta como vigente y una resuelta no', function (): void {
    expect(EstadoEnfermedad::Activa->estaVigente())->toBeTrue()
        ->and(EstadoEnfermedad::Cronica->estaVigente())->toBeTrue()
        ->and(EstadoEnfermedad::Resuelta->estaVigente())->toBeFalse();
});

it('rechaza un estado inventado', function (): void {
    [$usuario, $paciente] = fichaConEnfermedades();

    $this->actingAs($usuario)
        ->post(route('pacientes.enfermedades.store', $paciente), [
            'nombre' => 'Algo',
            'estado' => 'inventado',
        ])
        ->assertSessionHasErrors('estado');
});

/*
|--------------------------------------------------------------------------
| La fecha de diagnóstico: hoyCalendario(), no hoy()
|--------------------------------------------------------------------------
*/

it('rechaza una fecha de diagnóstico futura', function (): void {
    [$usuario, $paciente] = fichaConEnfermedades();

    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00', 'UTC'));

    $this->actingAs($usuario)
        ->post(route('pacientes.enfermedades.store', $paciente), [
            'nombre' => 'Algo',
            'estado' => EstadoEnfermedad::Activa->value,
            'fecha_diagnostico' => '2026-09-25',
        ])
        ->assertSessionHasErrors('fecha_diagnostico');
});

it('acepta HOY aunque sea de noche en Buenos Aires', function (): void {
    /*
     * A las 02:00 UTC del 25 son las 23:00 del 24 en Buenos Aires: el día
     * de esta persona todavía es el 24. Comparar contra `hoy()` -un
     * instante, la medianoche local en UTC- rechazaría el 24 por futuro.
     * Es la trampa que `FechaNoFutura` existe para no repetir.
     */
    [$usuario, $paciente] = fichaConEnfermedades();

    $this->travelTo(CarbonImmutable::parse('2026-09-25 02:00', 'UTC'));

    $this->actingAs($usuario)
        ->post(route('pacientes.enfermedades.store', $paciente), [
            'nombre' => 'Algo',
            'estado' => EstadoEnfermedad::Activa->value,
            'fecha_diagnostico' => '2026-09-24',
        ])
        ->assertSessionHasNoErrors();

    expect(Enfermedad::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| El médico: mismo criterio que el tipo de una medición
|--------------------------------------------------------------------------
*/

it('NO deja usar el médico de otro usuario', function (): void {
    [$usuario, $paciente] = fichaConEnfermedades();
    $ajeno = Medico::factory()->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.enfermedades.store', $paciente), [
            'nombre' => 'Algo',
            'estado' => EstadoEnfermedad::Activa->value,
            'medico_id' => $ajeno->id,
        ])
        ->assertSessionHasErrors('medico_id');
});

it('SÍ deja usar el médico propio', function (): void {
    [$usuario, $paciente] = fichaConEnfermedades();
    $mio = Medico::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.enfermedades.store', $paciente), [
            'nombre' => 'Algo',
            'estado' => EstadoEnfermedad::Activa->value,
            'medico_id' => $mio->id,
        ])
        ->assertSessionHasNoErrors();

    expect(Enfermedad::first()->medico_id)->toBe($mio->id);
});

it('un cuidador puede editar una enfermedad con el médico del dueño', function (): void {
    /*
     * Sin la mitad de "ya usado en esta ficha", editar las notas de una
     * enfermedad cargada por el dueño rebotaría por el médico que ya estaba
     * puesto: el catálogo es del dueño, no del cuidador.
     */
    [$duenio, $paciente] = fichaConEnfermedades();
    $medicoDelDuenio = Medico::factory()->for($duenio, 'usuario')->create();
    $enfermedad = Enfermedad::factory()->for($paciente)->create([
        'medico_id' => $medicoDelDuenio->id,
    ]);

    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->actingAs($cuidador)
        ->put(route('enfermedades.update', $enfermedad), [
            'nombre' => $enfermedad->nombre,
            'estado' => $enfermedad->estado->value,
            'medico_id' => $medicoDelDuenio->id,
            'notas' => 'Agregado por el cuidador',
        ])
        ->assertSessionHasNoErrors();

    expect($enfermedad->fresh()->notas)->toBe('Agregado por el cuidador');
});

it('una enfermedad sobrevive al borrado del médico', function (): void {
    // `nullOnDelete`: quién la diagnosticó es metadato, no es lo que la hace
    // legible. Al revés que el tipo de una medición, que es imprescindible.
    $medico = Medico::factory()->create();
    $enfermedad = Enfermedad::factory()->create(['medico_id' => $medico->id]);

    $medico->forceDelete();

    expect($enfermedad->fresh())->not->toBeNull()
        ->and($enfermedad->fresh()->medico_id)->toBeNull();
});

it('sigue mostrando el médico aunque esté en la papelera', function (): void {
    [$usuario, $paciente] = fichaConEnfermedades();
    $medico = Medico::factory()->for($usuario, 'usuario')->create(['nombre' => 'Dr. Borrado']);
    $enfermedad = Enfermedad::factory()->for($paciente)->create(['medico_id' => $medico->id]);

    $medico->delete();

    expect($enfermedad->fresh()->medico?->nombre)->toBe('Dr. Borrado');
});

/*
|--------------------------------------------------------------------------
| La bitácora
|--------------------------------------------------------------------------
*/

it('anota en la bitácora de una enfermedad', function (): void {
    [$usuario, $paciente] = fichaConEnfermedades();
    $enfermedad = Enfermedad::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->post(route('enfermedades.registros.store', $enfermedad), [
            'fecha' => '2026-09-20',
            'nota' => 'Empezó el tratamiento',
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    expect($enfermedad->registros()->count())->toBe(1)
        ->and($enfermedad->registros()->first()->nota)->toBe('Empezó el tratamiento');
});

it('cifra la nota de la bitácora', function (): void {
    $registro = RegistroEnfermedad::factory()->create(['nota' => 'Detalle Confidencial']);

    $crudo = DB::table('registros_enfermedad')->where('id', $registro->id)->first();

    expect($crudo->nota)->not->toContain('Confidencial')
        ->and($registro->fresh()->nota)->toBe('Detalle Confidencial');
});

it('la bitácora llega a su paciente en dos pasos', function (): void {
    // Sube por `enfermedad` y recién ahí encuentra al paciente: es el primer
    // registro clínico cuya cadena tiene más de un eslabón.
    $paciente = Paciente::factory()->create();
    $enfermedad = Enfermedad::factory()->for($paciente)->create();
    $registro = RegistroEnfermedad::factory()->for($enfermedad)->create();

    expect($registro->pacienteDelRegistro()?->id)->toBe($paciente->id);
});

it('un usuario ajeno no puede anotar ni borrar de la bitácora', function (): void {
    $enfermedad = Enfermedad::factory()->create();
    $registro = RegistroEnfermedad::factory()->for($enfermedad)->create();
    $ajeno = User::factory()->create();

    $this->actingAs($ajeno)
        ->post(route('enfermedades.registros.store', $enfermedad), [
            'fecha' => '2026-09-20',
            'nota' => 'Intento',
        ])
        ->assertForbidden();

    $this->actingAs($ajeno)
        ->delete(route('registros-enfermedad.destroy', $registro))
        ->assertForbidden();
});

it('borrar la enfermedad se lleva su bitácora', function (): void {
    // `cascadeOnDelete`: una anotación no existe sin su enfermedad.
    $enfermedad = Enfermedad::factory()->create();
    RegistroEnfermedad::factory()->for($enfermedad)->create();

    $enfermedad->forceDelete();

    expect(DB::table('registros_enfermedad')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| La curva: los números viven en mediciones, no en la bitácora
|--------------------------------------------------------------------------
*/

it('la ficha de la enfermedad trae la curva de sus mediciones', function (): void {
    [$usuario, $paciente] = fichaConEnfermedades();
    $enfermedad = Enfermedad::factory()->for($paciente)->create();
    $tipo = TipoMedicion::factory()->for($usuario, 'usuario')->create([
        'nombre' => 'Presión', 'unidad' => 'mmHg', 'decimales' => 0,
    ]);

    foreach (['120', '140'] as $valor) {
        Medicion::factory()->for($paciente)->for($tipo, 'tipo')
            ->create(['valor' => $valor, 'enfermedad_id' => $enfermedad->id]);
    }
    // Una de rutina, sin enfermedad: no tiene que aparecer en esta curva.
    Medicion::factory()->for($paciente)->for($tipo, 'tipo')->create(['valor' => '999']);

    $this->actingAs($usuario)
        ->get(route('pacientes.enfermedades.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->has('enfermedades.0.series', 1)
            ->where('enfermedades.0.series.0.resumen.cantidad', 2)
            ->where('enfermedades.0.series.0.resumen.promedio', '130')
        );
});

it('NO deja vincular una medición a la enfermedad de otro paciente', function (): void {
    /*
     * Sin esta condición, la curva de esa enfermedad mostraría valores de
     * otra persona y nada lo delataría en pantalla.
     */
    [$usuario, $paciente] = fichaConEnfermedades();
    $tipo = TipoMedicion::factory()->for($usuario, 'usuario')->create();
    $deOtro = Enfermedad::factory()->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $tipo->id,
            'enfermedad_id' => $deOtro->id,
            'fecha' => '2026-09-20T08:00',
            'valor' => '120',
        ])
        ->assertSessionHasErrors('enfermedad_id');

    expect(Medicion::count())->toBe(0);
});

it('borrar la enfermedad NO borra las mediciones, solo el vínculo', function (): void {
    // La medición es un dato por derecho propio: el peso de ese día sigue
    // siendo el peso de ese día.
    [$usuario, $paciente] = fichaConEnfermedades();
    $enfermedad = Enfermedad::factory()->for($paciente)->create();
    $tipo = TipoMedicion::factory()->for($usuario, 'usuario')->create();
    $medicion = Medicion::factory()->for($paciente)->for($tipo, 'tipo')
        ->create(['enfermedad_id' => $enfermedad->id]);

    $enfermedad->forceDelete();

    expect($medicion->fresh())->not->toBeNull()
        ->and($medicion->fresh()->enfermedad_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Autorización
|--------------------------------------------------------------------------
*/

it('un usuario ajeno no ve ni carga enfermedades', function (): void {
    [, $paciente] = fichaConEnfermedades();
    $ajeno = User::factory()->create();

    $this->actingAs($ajeno)
        ->get(route('pacientes.enfermedades.index', $paciente))
        ->assertForbidden();

    $this->actingAs($ajeno)
        ->post(route('pacientes.enfermedades.store', $paciente), [
            'nombre' => 'Intento',
            'estado' => EstadoEnfermedad::Activa->value,
        ])
        ->assertForbidden();
});

it('un lector ve pero no carga', function (): void {
    [, $paciente] = fichaConEnfermedades();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->get(route('pacientes.enfermedades.index', $paciente))
        ->assertOk();

    $this->actingAs($lector)
        ->post(route('pacientes.enfermedades.store', $paciente), [
            'nombre' => 'Intento',
            'estado' => EstadoEnfermedad::Activa->value,
        ])
        ->assertForbidden();
});

it('exige sesión', function (): void {
    [, $paciente] = fichaConEnfermedades();

    $this->get(route('pacientes.enfermedades.index', $paciente))
        ->assertRedirect(route('login'));
});
