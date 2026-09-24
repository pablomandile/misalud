<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Models\Medicion;
use App\Models\Paciente;
use App\Models\TipoMedicion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Un usuario con su paciente y un tipo propio, que es el caso de siempre.
 *
 * @return array{0: User, 1: Paciente, 2: TipoMedicion}
 */
function escenario(array $estadoDelTipo = []): array
{
    $usuario = User::factory()->create(['zona_horaria' => 'America/Argentina/Buenos_Aires']);
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $tipo = TipoMedicion::factory()->for($usuario, 'usuario')->create($estadoDelTipo);

    return [$usuario, $paciente, $tipo];
}

/*
|--------------------------------------------------------------------------
| Alta y listado
|--------------------------------------------------------------------------
*/

it('carga una medición en la ficha del paciente', function (): void {
    [$usuario, $paciente, $tipo] = escenario();

    $this->actingAs($usuario)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $tipo->id,
            'fecha' => '2026-09-20T08:00',
            'valor' => '72.5',
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    $medicion = Medicion::first();

    expect($medicion->paciente_id)->toBe($paciente->id)
        ->and($medicion->tipo_medicion_id)->toBe($tipo->id)
        ->and($medicion->valorNumerico())->toBe(72.5);
});

it('NO deja elegir en la ficha de quién escribir', function (): void {
    // `paciente_id` no es fillable: el paciente sale de la ruta, no del form.
    [$usuario, $paciente, $tipo] = escenario();
    $ajeno = Paciente::factory()->create();

    $this->actingAs($usuario)->post(route('pacientes.mediciones.store', $paciente), [
        'paciente_id' => $ajeno->id,
        'tipo_medicion_id' => $tipo->id,
        'fecha' => '2026-09-20T08:00',
        'valor' => '72.5',
    ]);

    expect(Medicion::first()->paciente_id)->toBe($paciente->id);
});

it('lista las mediciones de la más nueva a la más vieja', function (): void {
    [$usuario, $paciente, $tipo] = escenario();
    Medicion::factory()->for($paciente)->for($tipo, 'tipo')
        ->create(['fecha' => '2026-09-01 10:00', 'valor' => '70']);
    Medicion::factory()->for($paciente)->for($tipo, 'tipo')
        ->create(['fecha' => '2026-09-20 10:00', 'valor' => '80']);

    $this->actingAs($usuario)
        ->get(route('pacientes.mediciones.index', $paciente))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('mediciones/Index')
            ->has('mediciones', 2)
            ->where('mediciones.0.valor', 80)
            ->where('mediciones.1.valor', 70)
        );
});

it('cifra el valor y las notas en la base', function (): void {
    [, $paciente, $tipo] = escenario();
    $medicion = Medicion::factory()->for($paciente)->for($tipo, 'tipo')->create([
        'valor' => '123.45',
        'notas' => 'Después de caminar',
    ]);

    $crudo = DB::table('mediciones')->where('id', $medicion->id)->first();

    expect($crudo->valor)->not->toContain('123.45')
        ->and($crudo->notas)->not->toContain('caminar')
        // La fecha queda EN CLARO: es la columna por la que se ordena.
        ->and($crudo->fecha)->not->toBeNull()
        ->and($medicion->fresh()->valor)->toBe('123.45');
});

it('el valor vuelve como STRING y hay que pedir el número aparte', function (): void {
    // No hay cast `encrypted:float`: lo que sale de una columna cifrada es
    // texto. Comparar o sumar ese texto sin convertirlo no da error, da
    // resultados mal.
    [, $paciente, $tipo] = escenario();
    $medicion = Medicion::factory()->for($paciente)->for($tipo, 'tipo')->create(['valor' => '9']);

    expect($medicion->fresh()->valor)->toBeString()
        ->and($medicion->fresh()->valorNumerico())->toBeFloat()
        ->and($medicion->fresh()->valorNumerico())->toBe(9.0);
});

/*
|--------------------------------------------------------------------------
| La coma decimal: lo que escribe de verdad un teclado en español
|--------------------------------------------------------------------------
*/

it('acepta el valor escrito con COMA decimal', function (): void {
    /*
     * "72,5" es lo que ofrece un teclado numérico en español. Sin
     * normalizar, o lo rechaza la regla `numeric` -y la persona ve "el
     * valor debe ser un número" mirando un número válido- o un (float) lo
     * convierte en 72.0 y el peso pierde los gramos sin avisar.
     */
    [$usuario, $paciente, $tipo] = escenario();

    $this->actingAs($usuario)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $tipo->id,
            'fecha' => '2026-09-20T08:00',
            'valor' => '72,5',
        ])
        ->assertSessionHasNoErrors();

    expect(Medicion::first()->valorNumerico())->toBe(72.5);
});

it('la coma también vale para el segundo valor', function (): void {
    [$usuario, $paciente, $tipo] = escenario();
    $tipo->update(['etiqueta_secundaria' => 'Diastólica']);

    $this->actingAs($usuario)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $tipo->id,
            'fecha' => '2026-09-20T08:00',
            'valor' => '120,5',
            'valor_secundario' => '80,5',
        ])
        ->assertSessionHasNoErrors();

    expect(Medicion::first()->valorSecundarioNumerico())->toBe(80.5);
});

it('rechaza algo que no es un número', function (): void {
    [$usuario, $paciente, $tipo] = escenario();

    $this->actingAs($usuario)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $tipo->id,
            'fecha' => '2026-09-20T08:00',
            'valor' => 'setenta y dos',
        ])
        ->assertSessionHasErrors('valor');
});

/*
|--------------------------------------------------------------------------
| Los dos valores (presión): el caso que define el esquema
|--------------------------------------------------------------------------
*/

it('un tipo de dos valores EXIGE el segundo', function (): void {
    // Una presión sin diastólica no es media presión: es un dato que no se
    // puede leer.
    [$usuario, $paciente, $tipo] = escenario();
    $tipo->update(['etiqueta_secundaria' => 'Diastólica']);

    $this->actingAs($usuario)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $tipo->id,
            'fecha' => '2026-09-20T08:00',
            'valor' => '120',
        ])
        ->assertSessionHasErrors('valor_secundario');

    expect(Medicion::count())->toBe(0);
});

it('un tipo de UN valor RECHAZA el segundo', function (): void {
    // Un peso con un segundo número es un fantasma que después nadie sabe
    // qué significaba.
    [$usuario, $paciente, $tipo] = escenario();

    $this->actingAs($usuario)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $tipo->id,
            'fecha' => '2026-09-20T08:00',
            'valor' => '72.5',
            'valor_secundario' => '80',
        ])
        ->assertSessionHasErrors('valor_secundario');

    expect(Medicion::count())->toBe(0);
});

it('guarda los dos números de una presión', function (): void {
    [$usuario, $paciente] = escenario();
    $presion = TipoMedicion::factory()->deDosValores()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $presion->id,
            'fecha' => '2026-09-20T08:00',
            'valor' => '120',
            'valor_secundario' => '80',
        ])
        ->assertSessionHasNoErrors();

    $medicion = Medicion::first();

    expect($medicion->valorNumerico())->toBe(120.0)
        ->and($medicion->valorSecundarioNumerico())->toBe(80.0);
});

it('el listado arma el valor visible con los decimales del tipo', function (): void {
    [$usuario, $paciente] = escenario();
    $tipo = TipoMedicion::factory()->for($usuario, 'usuario')->create([
        'nombre' => 'Peso',
        'unidad' => 'kg',
        'decimales' => 1,
    ]);
    Medicion::factory()->for($paciente)->for($tipo, 'tipo')->create(['valor' => '72.5']);

    $this->actingAs($usuario)
        ->get(route('pacientes.mediciones.index', $paciente))
        ->assertInertia(fn ($p) => $p
            // Coma decimal para mostrar; float aparte para el gráfico.
            ->where('mediciones.0.valorVisible', '72,5')
            ->where('mediciones.0.valor', 72.5)
        );
});

/*
|--------------------------------------------------------------------------
| Zona horaria: lo que escribe la persona NO es UTC
|--------------------------------------------------------------------------
*/

it('guarda en UTC la hora local de quien carga', function (): void {
    /*
     * Las 23:30 del 24 en Buenos Aires son las 02:30 del 25 en UTC.
     * Guardar el texto tal cual lo dejaría corrido tres horas, y sin
     * ningún síntoma hasta que alguien mira la hora.
     */
    [$usuario, $paciente, $tipo] = escenario();

    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00', 'UTC'));

    $this->actingAs($usuario)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $tipo->id,
            'fecha' => '2026-09-24T23:30',
            'valor' => '72.5',
        ])
        ->assertSessionHasNoErrors();

    expect(Medicion::first()->fecha->utc()->format('Y-m-d H:i'))->toBe('2026-09-25 02:30');
});

it('muestra la fecha de vuelta en la zona de quien mira', function (): void {
    [$usuario, $paciente, $tipo] = escenario();
    Medicion::factory()->for($paciente)->for($tipo, 'tipo')
        ->create(['fecha' => CarbonImmutable::parse('2026-09-25 02:30', 'UTC')]);

    $this->actingAs($usuario)
        ->get(route('pacientes.mediciones.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->where('mediciones.0.fechaVisible', '24/09/2026 23:30')
            // La misma fecha en el formato que entiende un datetime-local.
            ->where('mediciones.0.fechaLocal', '2026-09-24T23:30')
        );
});

it('rechaza una fecha futura, medida contra la zona de la persona', function (): void {
    /*
     * A las 23:00 del 24 en Buenos Aires (02:00 UTC del 25), cargar algo a
     * las 23:30 locales es futuro. Una implementación que leyera el texto
     * como UTC calcularía 23:30 UTC del 24 -tres horas en el pasado- y lo
     * dejaría pasar.
     */
    [$usuario, $paciente, $tipo] = escenario();

    $this->travelTo(CarbonImmutable::parse('2026-09-25 02:00', 'UTC'));

    $this->actingAs($usuario)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $tipo->id,
            'fecha' => '2026-09-24T23:30',
            'valor' => '72.5',
        ])
        ->assertSessionHasErrors('fecha');

    expect(Medicion::count())->toBe(0);
});

it('le perdona unos minutos al reloj del dispositivo', function (): void {
    // Un celular que adelanta dos minutos no puede impedir cargar lo que la
    // persona acaba de medir.
    [$usuario, $paciente, $tipo] = escenario();

    $this->travelTo(CarbonImmutable::parse('2026-09-25 02:00', 'UTC'));

    $this->actingAs($usuario)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $tipo->id,
            'fecha' => $usuario->ahora()->addMinutes(2)->format('Y-m-d\TH:i'),
            'valor' => '72.5',
        ])
        ->assertSessionHasNoErrors();

    expect(Medicion::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Qué tipo se puede elegir (el OR agrupado, tercera aparición)
|--------------------------------------------------------------------------
*/

it('NO deja usar el tipo de otro usuario', function (): void {
    [$usuario, $paciente] = escenario();
    $ajeno = TipoMedicion::factory()->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $ajeno->id,
            'fecha' => '2026-09-20T08:00',
            'valor' => '72.5',
        ])
        ->assertSessionHasErrors('tipo_medicion_id');

    expect(Medicion::count())->toBe(0);
});

it('SÍ deja usar una semilla compartida', function (): void {
    [$usuario, $paciente] = escenario();
    $semilla = TipoMedicion::factory()->semilla()->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $semilla->id,
            'fecha' => '2026-09-20T08:00',
            'valor' => '72.5',
        ])
        ->assertSessionHasNoErrors();

    expect(Medicion::count())->toBe(1);
});

it('el formulario ofrece los tipos propios y las semillas, no los ajenos', function (): void {
    [$usuario, $paciente] = escenario();
    TipoMedicion::factory()->semilla()->create();
    TipoMedicion::factory()->create();

    $this->actingAs($usuario)
        ->get(route('pacientes.mediciones.index', $paciente))
        // El de escenario() + la semilla. El ajeno no.
        ->assertInertia(fn ($p) => $p->has('tipos', 2));
});

/*
|--------------------------------------------------------------------------
| Autorización: la da el rol en el pivote
|--------------------------------------------------------------------------
*/

it('un usuario ajeno no ve ni carga mediciones', function (): void {
    [, $paciente, $tipo] = escenario();
    $ajeno = User::factory()->create();

    $this->actingAs($ajeno)
        ->get(route('pacientes.mediciones.index', $paciente))
        ->assertForbidden();

    $this->actingAs($ajeno)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $tipo->id,
            'fecha' => '2026-09-20T08:00',
            'valor' => '72.5',
        ])
        ->assertForbidden();
});

it('un lector VE pero no carga, ni edita, ni borra', function (): void {
    [, $paciente, $tipo] = escenario();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);
    $medicion = Medicion::factory()->for($paciente)->for($tipo, 'tipo')->create();

    $this->actingAs($lector)
        ->get(route('pacientes.mediciones.index', $paciente))
        ->assertOk();

    $this->actingAs($lector)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $tipo->id,
            'fecha' => '2026-09-20T08:00',
            'valor' => '72.5',
        ])
        ->assertForbidden();

    $this->actingAs($lector)
        ->delete(route('mediciones.destroy', $medicion))
        ->assertForbidden();
});

it('un cuidador carga con una semilla compartida', function (): void {
    [, $paciente] = escenario();
    $semilla = TipoMedicion::factory()->semilla()->create();
    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->actingAs($cuidador)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $semilla->id,
            'fecha' => '2026-09-20T08:00',
            'valor' => '72.5',
        ])
        ->assertSessionHasNoErrors();

    expect(Medicion::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Una ficha compartida comparte su vocabulario
|--------------------------------------------------------------------------
|
| Los catálogos son del USUARIO, pero una ficha la escriben varios. Sin esta
| regla, un cuidador no podría sumarle un peso a la serie que ya existe
| -porque el tipo es del dueño, no suyo- y terminaría creando un "Peso"
| propio: la misma variable partida en dos.
|
*/

it('un cuidador puede seguir una serie que arrancó el dueño', function (): void {
    [, $paciente, $tipoDelDuenio] = escenario();
    Medicion::factory()->for($paciente)->for($tipoDelDuenio, 'tipo')->create();

    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->actingAs($cuidador)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $tipoDelDuenio->id,
            'fecha' => '2026-09-20T08:00',
            'valor' => '73',
        ])
        ->assertSessionHasNoErrors();

    expect($paciente->mediciones()->count())->toBe(2);
});

it('el formulario del cuidador ofrece lo que la ficha ya usa', function (): void {
    // Las dos mitades tienen que coincidir: si el formulario ofreciera algo
    // que la validación rechaza, el error aparecería recién al guardar.
    [, $paciente, $tipoDelDuenio] = escenario();
    Medicion::factory()->for($paciente)->for($tipoDelDuenio, 'tipo')->create();

    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->actingAs($cuidador)
        ->get(route('pacientes.mediciones.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->has('tipos', 1)
            ->where('tipos.0.id', $tipoDelDuenio->id)
        );
});

it('pero NO le abre el resto del catálogo del dueño', function (): void {
    // Solo lo que la ficha ya usa -y que el cuidador ya está viendo en el
    // listado-. El resto del catálogo del dueño sigue siendo suyo.
    [$duenio, $paciente, $tipoUsado] = escenario();
    Medicion::factory()->for($paciente)->for($tipoUsado, 'tipo')->create();
    $tipoNuncaUsado = TipoMedicion::factory()->for($duenio, 'usuario')->create();

    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->actingAs($cuidador)
        ->post(route('pacientes.mediciones.store', $paciente), [
            'tipo_medicion_id' => $tipoNuncaUsado->id,
            'fecha' => '2026-09-20T08:00',
            'valor' => '73',
        ])
        ->assertSessionHasErrors('tipo_medicion_id');
});

/*
|--------------------------------------------------------------------------
| Editar y borrar
|--------------------------------------------------------------------------
*/

it('corrige un valor mal cargado', function (): void {
    [$usuario, $paciente, $tipo] = escenario();
    $medicion = Medicion::factory()->for($paciente)->for($tipo, 'tipo')->create(['valor' => '7.25']);

    $this->actingAs($usuario)
        ->put(route('mediciones.update', $medicion), [
            'tipo_medicion_id' => $tipo->id,
            'fecha' => '2026-09-20T08:00',
            'valor' => '72,5',
        ])
        ->assertSessionHasNoErrors();

    expect($medicion->fresh()->valorNumerico())->toBe(72.5);
});

it('el dueño borra su medición', function (): void {
    [$usuario, $paciente, $tipo] = escenario();
    $medicion = Medicion::factory()->for($paciente)->for($tipo, 'tipo')->create();

    $this->actingAs($usuario)
        ->delete(route('mediciones.destroy', $medicion))
        ->assertRedirect()
        ->assertSessionHas('exito');

    expect(Medicion::find($medicion->id))->toBeNull();
});

it('exige sesión', function (): void {
    [, $paciente] = escenario();

    $this->get(route('pacientes.mediciones.index', $paciente))->assertRedirect(route('login'));
});
