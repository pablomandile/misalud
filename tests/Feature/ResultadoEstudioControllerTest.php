<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Models\Estudio;
use App\Models\Paciente;
use App\Models\ResultadoEstudio;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Cargar un parámetro es editar el estudio
|--------------------------------------------------------------------------
*/

it('carga un parámetro en el estudio', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $estudio = Estudio::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->post(route('estudios.resultados.store', $estudio), [
            'parametro' => 'Glucemia',
            'valor' => '90',
            'unidad' => 'mg/dl',
            'rango_referencia' => '70 a 110',
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    $resultado = $estudio->resultados()->first();

    expect($resultado->parametro)->toBe('Glucemia')
        ->and($resultado->valor)->toBe('90')
        ->and($resultado->unidad)->toBe('mg/dl');
});

it('cifra el parámetro, el valor, la unidad y el rango', function (): void {
    $resultado = ResultadoEstudio::factory()->create([
        'parametro' => 'Parámetro Confidencial',
        'valor' => '123',
        'unidad' => 'unidad-secreta',
        'rango_referencia' => 'rango-reservado',
    ]);

    $crudo = DB::table('resultados_estudio')->where('id', $resultado->id)->first();

    expect($crudo->parametro)->not->toContain('Confidencial')
        ->and($crudo->valor)->not->toContain('123')
        ->and($crudo->unidad)->not->toContain('unidad-secreta')
        ->and($crudo->rango_referencia)->not->toContain('reservado')
        ->and($resultado->fresh()->parametro)->toBe('Parámetro Confidencial');
});

it('acepta la coma decimal en el valor', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $estudio = Estudio::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->post(route('estudios.resultados.store', $estudio), [
            'parametro' => 'Colesterol',
            'valor' => '180,5',
        ])
        ->assertSessionHasNoErrors();

    expect($estudio->resultados()->first()->valor)->toBe('180.5');
});

it('un valor no numérico es válido: "Positivo" es un resultado real', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $estudio = Estudio::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->post(route('estudios.resultados.store', $estudio), [
            'parametro' => 'VDRL',
            'valor' => 'No reactivo',
        ])
        ->assertSessionHasNoErrors();

    expect($estudio->resultados()->first()->valor)->toBe('No reactivo');
});

it('valorNumerico() devuelve null para lo que no es un número', function (): void {
    $numerico = ResultadoEstudio::factory()->create(['valor' => '90']);
    $texto = ResultadoEstudio::factory()->create(['valor' => 'Positivo']);

    expect($numerico->fresh()->valorNumerico())->toBe(90.0)
        ->and($texto->fresh()->valorNumerico())->toBeNull();
});

it('exige el parámetro y el valor', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $estudio = Estudio::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->post(route('estudios.resultados.store', $estudio), [])
        ->assertSessionHasErrors(['parametro', 'valor']);
});

it('el segundo registro clínico que llega a su paciente en dos pasos', function (): void {
    // Sube por `estudio` y recién ahí lo encuentra, como RegistroEnfermedad.
    $paciente = Paciente::factory()->create();
    $estudio = Estudio::factory()->for($paciente)->create();
    $resultado = ResultadoEstudio::factory()->for($estudio)->create();

    expect($resultado->pacienteDelRegistro()?->id)->toBe($paciente->id);
});

it('borrar el estudio se lleva sus resultados', function (): void {
    // cascadeOnDelete: un resultado no existe sin su estudio.
    $estudio = Estudio::factory()->create();
    ResultadoEstudio::factory()->for($estudio)->create();

    $estudio->forceDelete();

    expect(DB::table('resultados_estudio')->count())->toBe(0);
});

it('el listado trae los resultados del estudio', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $estudio = Estudio::factory()->for($paciente)->create();
    ResultadoEstudio::factory()->for($estudio)->create(['parametro' => 'Glucemia', 'valor' => '90']);

    $this->actingAs($usuario)
        ->get(route('pacientes.estudios.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->has('estudios.0.resultados', 1)
            ->where('estudios.0.resultados.0.parametro', 'Glucemia')
            ->where('estudios.0.resultados.0.valor', '90')
        );
});

/*
|--------------------------------------------------------------------------
| Editar y borrar un resultado: SÍ se puede corregir, a diferencia de la
| bitácora de una enfermedad
|--------------------------------------------------------------------------
*/

it('corrige un valor mal cargado', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $estudio = Estudio::factory()->for($paciente)->create();
    $resultado = ResultadoEstudio::factory()->for($estudio)->create(['valor' => '900']);

    $this->actingAs($usuario)
        ->put(route('resultados.update', $resultado), [
            'parametro' => $resultado->parametro,
            'valor' => '90',
        ])
        ->assertSessionHasNoErrors();

    expect($resultado->fresh()->valor)->toBe('90');
});

it('el dueño borra un resultado', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $estudio = Estudio::factory()->for($paciente)->create();
    $resultado = ResultadoEstudio::factory()->for($estudio)->create();

    $this->actingAs($usuario)
        ->delete(route('resultados.destroy', $resultado))
        ->assertRedirect()
        ->assertSessionHas('exito');

    expect(ResultadoEstudio::find($resultado->id))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Autorización: la Policy del estudio, no una propia
|--------------------------------------------------------------------------
*/

it('un usuario ajeno no puede cargar ni borrar resultados', function (): void {
    $estudio = Estudio::factory()->create();
    $resultado = ResultadoEstudio::factory()->for($estudio)->create();
    $ajeno = User::factory()->create();

    $this->actingAs($ajeno)
        ->post(route('estudios.resultados.store', $estudio), [
            'parametro' => 'Intento',
            'valor' => '1',
        ])
        ->assertForbidden();

    $this->actingAs($ajeno)
        ->delete(route('resultados.destroy', $resultado))
        ->assertForbidden();
});

it('un lector NO puede cargar ni editar resultados', function (): void {
    $paciente = Paciente::factory()->create();
    $estudio = Estudio::factory()->for($paciente)->create();
    $resultado = ResultadoEstudio::factory()->for($estudio)->create();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->post(route('estudios.resultados.store', $estudio), [
            'parametro' => 'Intento',
            'valor' => '1',
        ])
        ->assertForbidden();

    $this->actingAs($lector)
        ->put(route('resultados.update', $resultado), [
            'parametro' => $resultado->parametro,
            'valor' => '1',
        ])
        ->assertForbidden();
});

it('un cuidador SÍ puede cargar resultados', function (): void {
    $paciente = Paciente::factory()->create();
    $estudio = Estudio::factory()->for($paciente)->create();
    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->actingAs($cuidador)
        ->post(route('estudios.resultados.store', $estudio), [
            'parametro' => 'Glucemia',
            'valor' => '90',
        ])
        ->assertSessionHasNoErrors();

    expect($estudio->resultados()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| La evolución (9.4): el mismo parámetro a través de varios estudios
|--------------------------------------------------------------------------
*/

it('agrupa el mismo parámetro entre DOS estudios distintos', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $estudio1 = Estudio::factory()->for($paciente)->create(['fecha' => '2026-08-01']);
    $estudio2 = Estudio::factory()->for($paciente)->create(['fecha' => '2026-09-01']);
    ResultadoEstudio::factory()->for($estudio1)->create(['parametro' => 'Glucemia', 'valor' => '90']);
    ResultadoEstudio::factory()->for($estudio2)->create(['parametro' => 'Glucemia', 'valor' => '110']);

    $this->actingAs($usuario)
        ->get(route('pacientes.estudios.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->has('evolucion', 1)
            ->where('evolucion.0.parametro', 'Glucemia')
            ->where('evolucion.0.resumen.cantidad', 2)
            ->where('evolucion.0.resumen.minimo', '90')
            ->where('evolucion.0.resumen.maximo', '110')
            ->where('evolucion.0.resumen.promedio', '100')
        );
});

it('un parámetro con un solo resultado NO entra a la evolución', function (): void {
    // Una línea de un punto no es una evolución.
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $estudio = Estudio::factory()->for($paciente)->create();
    ResultadoEstudio::factory()->for($estudio)->create(['parametro' => 'Colesterol']);

    $this->actingAs($usuario)
        ->get(route('pacientes.estudios.index', $paciente))
        ->assertInertia(fn ($p) => $p->has('evolucion', 0));
});

it('un valor no numérico NO entra a la evolución', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $estudio1 = Estudio::factory()->for($paciente)->create(['fecha' => '2026-08-01']);
    $estudio2 = Estudio::factory()->for($paciente)->create(['fecha' => '2026-09-01']);
    ResultadoEstudio::factory()->for($estudio1)->create(['parametro' => 'VDRL', 'valor' => 'No reactivo']);
    ResultadoEstudio::factory()->for($estudio2)->create(['parametro' => 'VDRL', 'valor' => 'Reactivo']);

    $this->actingAs($usuario)
        ->get(route('pacientes.estudios.index', $paciente))
        ->assertInertia(fn ($p) => $p->has('evolucion', 0));
});

it('parámetros DISTINTOS no se mezclan en la misma serie', function (): void {
    // "Glucemia" en un estudio y "Colesterol" en otro no son el mismo grupo,
    // aunque los dos tengan solo un resultado cada uno.
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $estudio1 = Estudio::factory()->for($paciente)->create();
    $estudio2 = Estudio::factory()->for($paciente)->create();
    ResultadoEstudio::factory()->for($estudio1)->create(['parametro' => 'Glucemia', 'valor' => '90']);
    ResultadoEstudio::factory()->for($estudio1)->create(['parametro' => 'Glucemia', 'valor' => '95']);
    ResultadoEstudio::factory()->for($estudio2)->create(['parametro' => 'Colesterol', 'valor' => '180']);

    $this->actingAs($usuario)
        ->get(route('pacientes.estudios.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->has('evolucion', 1)
            ->where('evolucion.0.parametro', 'Glucemia')
        );
});

it('el mismo parámetro de otro paciente NO se mezcla', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $otroPaciente = Paciente::factory()->for($usuario, 'usuario')->create();

    $estudio1 = Estudio::factory()->for($paciente)->create();
    $estudio2 = Estudio::factory()->for($paciente)->create();
    ResultadoEstudio::factory()->for($estudio1)->create(['parametro' => 'Glucemia', 'valor' => '90']);
    ResultadoEstudio::factory()->for($estudio2)->create(['parametro' => 'Glucemia', 'valor' => '95']);

    $estudioAjeno = Estudio::factory()->for($otroPaciente)->create();
    ResultadoEstudio::factory()->for($estudioAjeno)->create(['parametro' => 'Glucemia', 'valor' => '999']);

    $this->actingAs($usuario)
        ->get(route('pacientes.estudios.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->has('evolucion', 1)
            ->where('evolucion.0.resumen.cantidad', 2)
            ->where('evolucion.0.resumen.maximo', '95')
        );
});

it('los puntos van ordenados por la fecha del estudio, no por el orden de carga', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $estudioReciente = Estudio::factory()->for($paciente)->create(['fecha' => '2026-09-01']);
    $estudioViejo = Estudio::factory()->for($paciente)->create(['fecha' => '2026-01-01']);

    // Se cargan en orden "al revés" del cronológico.
    ResultadoEstudio::factory()->for($estudioReciente)->create(['parametro' => 'Glucemia', 'valor' => '110']);
    ResultadoEstudio::factory()->for($estudioViejo)->create(['parametro' => 'Glucemia', 'valor' => '90']);

    $this->actingAs($usuario)
        ->get(route('pacientes.estudios.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->where('evolucion.0.puntos.0.valor', 90)
            ->where('evolucion.0.puntos.1.valor', 110)
        );
});
