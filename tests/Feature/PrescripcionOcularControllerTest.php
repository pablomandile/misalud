<?php

declare(strict_types=1);

use App\Enums\Ojo;
use App\Enums\RolPaciente;
use App\Enums\TipoAdjunto;
use App\Enums\TipoPrescripcionOcular;
use App\Models\Estudio;
use App\Models\GraduacionOcular;
use App\Models\Medico;
use App\Models\OrdenEstudio;
use App\Models\Paciente;
use App\Models\PrescripcionOcular;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * @return array{0: User, 1: Paciente}
 */
function fichaOcular(): array
{
    $usuario = User::factory()->create(['zona_horaria' => 'America/Argentina/Buenos_Aires']);
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    return [$usuario, $paciente];
}

/**
 * Una receta que pasa todas las reglas, para que cada test cambie una sola
 * cosa y se vea qué es lo que está probando.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function recetaValida(array $extra = []): array
{
    return array_merge([
        'tipo' => TipoPrescripcionOcular::Lejos->value,
        'fecha' => '2026-09-20',
        'od' => ['esfera' => '-1.25', 'cilindro' => '-0.50', 'eje' => '180'],
        'oi' => ['esfera' => '-1.00', 'cilindro' => '-0.25', 'eje' => '170'],
    ], $extra);
}

/*
|--------------------------------------------------------------------------
| El invariante: una receta tiene DOS ojos
|--------------------------------------------------------------------------
*/

it('carga una receta con la graduación de los dos ojos', function (): void {
    [$usuario, $paciente] = fichaOcular();

    $this->actingAs($usuario)
        ->post(route('pacientes.salud-ocular.store', $paciente), recetaValida())
        ->assertRedirect()
        ->assertSessionHas('exito');

    $receta = PrescripcionOcular::first();

    expect($receta->paciente_id)->toBe($paciente->id)
        ->and($receta->tipo)->toBe(TipoPrescripcionOcular::Lejos)
        ->and($receta->graduaciones()->count())->toBe(2)
        ->and($receta->graduacionDe(Ojo::Derecho)->esfera)->toBe('-1.25')
        ->and($receta->graduacionDe(Ojo::Izquierdo)->esfera)->toBe('-1.00');
});

it('guarda los DOS ojos aunque el formulario solo traiga uno', function (): void {
    /*
     * Es el invariante de la etapa: una fila que falta no se sabe si es un
     * ojo sano o una carga a medias. Un ojo en blanco sí se sabe.
     */
    [$usuario, $paciente] = fichaOcular();

    $this->actingAs($usuario)->post(
        route('pacientes.salud-ocular.store', $paciente),
        recetaValida(['oi' => []]),
    )->assertSessionHasNoErrors();

    $receta = PrescripcionOcular::first();
    $izquierdo = $receta->graduacionDe(Ojo::Izquierdo);

    expect($receta->graduaciones()->count())->toBe(2)
        ->and($izquierdo)->not->toBeNull()
        ->and($izquierdo->tieneDatos())->toBeFalse();
});

it('no admite dos filas del mismo ojo en una receta', function (): void {
    $receta = PrescripcionOcular::factory()->create();

    expect(fn () => $receta->graduaciones()->create(['ojo' => Ojo::Derecho]))
        ->toThrow(QueryException::class);
});

it('NO deja elegir en la ficha de quién escribir', function (): void {
    [$usuario, $paciente] = fichaOcular();
    $ajeno = Paciente::factory()->create();

    $this->actingAs($usuario)->post(
        route('pacientes.salud-ocular.store', $paciente),
        recetaValida(['paciente_id' => $ajeno->id]),
    );

    expect(PrescripcionOcular::first()->paciente_id)->toBe($paciente->id);
});

it('cifra los valores de la graduación y deja el ojo en claro', function (): void {
    $receta = PrescripcionOcular::factory()
        ->conOjos(od: ['agudeza_visual' => 'Cuenta dedos a dos metros'])
        ->create(['notas' => 'Comentario reservado']);

    $crudoReceta = DB::table('prescripciones_oculares')->where('id', $receta->id)->first();
    $crudoOjo = DB::table('graduaciones_oculares')
        ->where('prescripcion_id', $receta->id)
        ->where('ojo', 'od')
        ->first();

    expect($crudoReceta->notas)->not->toContain('reservado')
        ->and($crudoOjo->agudeza_visual)->not->toContain('Cuenta dedos')
        ->and($crudoOjo->esfera)->not->toBeNull()
        // `ojo` en claro: es la mitad del UNIQUE y no es contenido clínico.
        ->and($crudoOjo->ojo)->toBe('od')
        ->and($receta->fresh()->graduacionDe(Ojo::Derecho)->agudeza_visual)
        ->toBe('Cuenta dedos a dos metros');
});

/*
|--------------------------------------------------------------------------
| Validación clínica: se rechaza lo que no puede existir
|--------------------------------------------------------------------------
*/

it('rechaza una esfera que no va de 0,25 en 0,25', function (): void {
    // -1,30 no es una graduación rara: no se fabrica. Es un tipeo.
    [$usuario, $paciente] = fichaOcular();

    $this->actingAs($usuario)->post(
        route('pacientes.salud-ocular.store', $paciente),
        recetaValida(['od' => ['esfera' => '-1.30']]),
    )->assertSessionHasErrors('od.esfera');
});

it('acepta los cuartos que el punto flotante hace parecer inválidos', function (): void {
    /*
     * `fmod(0.75, 0.25)` puede dar 2.7E-17 en binario. Si la regla usara
     * `fmod`, rechazaría valores perfectamente válidos, que es peor que no
     * validar nada.
     */
    [$usuario, $paciente] = fichaOcular();

    $this->actingAs($usuario)->post(
        route('pacientes.salud-ocular.store', $paciente),
        recetaValida(['od' => ['esfera' => '0.75', 'cilindro' => '-2.25', 'eje' => '45']]),
    )->assertSessionHasNoErrors();
});

it('acepta la coma decimal que escribe un teclado en español', function (): void {
    [$usuario, $paciente] = fichaOcular();

    $this->actingAs($usuario)->post(
        route('pacientes.salud-ocular.store', $paciente),
        recetaValida(['od' => ['esfera' => '-1,25', 'cilindro' => '-0,75', 'eje' => '90']]),
    )->assertSessionHasNoErrors();

    expect(PrescripcionOcular::first()->graduacionDe(Ojo::Derecho)->numero('esfera'))
        ->toBe(-1.25);
});

it('rechaza una esfera fuera de lo que existe', function (): void {
    [$usuario, $paciente] = fichaOcular();

    $this->actingAs($usuario)->post(
        route('pacientes.salud-ocular.store', $paciente),
        // El tipeo clásico: 125 por 1,25.
        recetaValida(['od' => ['esfera' => '125']]),
    )->assertSessionHasErrors('od.esfera');
});

it('exige el eje cuando hay cilindro: sin eje la lente no se puede fabricar', function (): void {
    [$usuario, $paciente] = fichaOcular();

    $this->actingAs($usuario)->post(
        route('pacientes.salud-ocular.store', $paciente),
        recetaValida(['od' => ['esfera' => '-1.00', 'cilindro' => '-0.75']]),
    )->assertSessionHasErrors('od.eje');
});

it('prohíbe el eje cuando no hay cilindro: solo no significa nada', function (): void {
    [$usuario, $paciente] = fichaOcular();

    $this->actingAs($usuario)->post(
        route('pacientes.salud-ocular.store', $paciente),
        recetaValida(['od' => ['esfera' => '-1.00', 'eje' => '90']]),
    )->assertSessionHasErrors('od.eje');
});

it('trata un cilindro en 0 como si no hubiera cilindro', function (): void {
    // "0,00 x 180" es una costumbre de escritura, no una corrección.
    [$usuario, $paciente] = fichaOcular();

    $this->actingAs($usuario)->post(
        route('pacientes.salud-ocular.store', $paciente),
        recetaValida(['od' => ['esfera' => '-1.00', 'cilindro' => '0']]),
    )->assertSessionHasNoErrors();
});

it('rechaza un eje mayor a 180, que es un meridiano que no existe', function (): void {
    [$usuario, $paciente] = fichaOcular();

    $this->actingAs($usuario)->post(
        route('pacientes.salud-ocular.store', $paciente),
        recetaValida(['od' => ['esfera' => '-1.00', 'cilindro' => '-0.50', 'eje' => '200']]),
    )->assertSessionHasErrors('od.eje');
});

it('rechaza una adición negativa: una adición es una suma', function (): void {
    [$usuario, $paciente] = fichaOcular();

    $this->actingAs($usuario)->post(
        route('pacientes.salud-ocular.store', $paciente),
        recetaValida(['od' => ['esfera' => '-1.00', 'adicion' => '-2.00']]),
    )->assertSessionHasErrors('od.adicion');
});

it('exige la base cuando hay prisma, y el prisma cuando hay base', function (): void {
    [$usuario, $paciente] = fichaOcular();

    $this->actingAs($usuario)->post(
        route('pacientes.salud-ocular.store', $paciente),
        recetaValida(['od' => ['esfera' => '-1.00', 'prisma' => '2']]),
    )->assertSessionHasErrors('od.base');

    $this->actingAs($usuario)->post(
        route('pacientes.salud-ocular.store', $paciente),
        recetaValida(['oi' => ['esfera' => '-1.00', 'base' => 'base externa']]),
    )->assertSessionHasErrors('oi.prisma');
});

it('NO rechaza una adición en una receta para lejos', function (): void {
    /*
     * Es raro, pero existe —un papel puede traer las dos cosas—, y
     * rechazarlo sería opinar sobre la receta de otro: regla 1, el sistema
     * registra y no aconseja. La validación ataja lo imposible, no lo
     * infrecuente.
     */
    [$usuario, $paciente] = fichaOcular();

    $this->actingAs($usuario)->post(
        route('pacientes.salud-ocular.store', $paciente),
        recetaValida([
            'tipo' => TipoPrescripcionOcular::Lejos->value,
            'od' => ['esfera' => '-1.00', 'adicion' => '2.00'],
        ]),
    )->assertSessionHasNoErrors();
});

it('rechaza una receta con fecha futura', function (): void {
    [$usuario, $paciente] = fichaOcular();

    $this->actingAs($usuario)->post(
        route('pacientes.salud-ocular.store', $paciente),
        recetaValida(['fecha' => now()->addDays(3)->format('Y-m-d')]),
    )->assertSessionHasErrors('fecha');
});

it('exige el tipo y la fecha, y rechaza un tipo inventado', function (): void {
    [$usuario, $paciente] = fichaOcular();

    $this->actingAs($usuario)
        ->post(route('pacientes.salud-ocular.store', $paciente), [])
        ->assertSessionHasErrors(['tipo', 'fecha']);

    $this->actingAs($usuario)->post(
        route('pacientes.salud-ocular.store', $paciente),
        recetaValida(['tipo' => 'para-el-sol']),
    )->assertSessionHasErrors('tipo');
});

it('no deja elegir un médico que no es de este usuario', function (): void {
    [$usuario, $paciente] = fichaOcular();
    $ajeno = Medico::factory()->create(['usuario_id' => User::factory()]);

    $this->actingAs($usuario)->post(
        route('pacientes.salud-ocular.store', $paciente),
        recetaValida(['medico_id' => $ajeno->id]),
    )->assertSessionHasErrors('medico_id');
});

/*
|--------------------------------------------------------------------------
| Edición
|--------------------------------------------------------------------------
*/

it('al editar actualiza los dos ojos sin duplicar filas', function (): void {
    [$usuario, $paciente] = fichaOcular();
    $receta = PrescripcionOcular::factory()->for($paciente)->create();

    $this->actingAs($usuario)->put(
        route('salud-ocular.update', $receta),
        recetaValida(['od' => ['esfera' => '-4.00', 'cilindro' => '-1.00', 'eje' => '10']]),
    )->assertSessionHasNoErrors();

    expect($receta->fresh()->graduaciones()->count())->toBe(2)
        ->and($receta->fresh()->graduacionDe(Ojo::Derecho)->esfera)->toBe('-4.00');
});

it('al editar completa un ojo que faltara en la base', function (): void {
    [$usuario, $paciente] = fichaOcular();
    $receta = PrescripcionOcular::factory()->for($paciente)->create();
    $receta->graduaciones()->where('ojo', Ojo::Izquierdo)->delete();

    $this->actingAs($usuario)
        ->put(route('salud-ocular.update', $receta), recetaValida())
        ->assertSessionHasNoErrors();

    expect($receta->fresh()->graduaciones()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Lo que ve la pantalla
|--------------------------------------------------------------------------
*/

it('manda los dos ojos, con OD primero y la receta más nueva arriba', function (): void {
    [$usuario, $paciente] = fichaOcular();
    PrescripcionOcular::factory()->for($paciente)->create(['fecha' => '2024-01-10']);
    PrescripcionOcular::factory()->for($paciente)->create(['fecha' => '2026-03-15']);

    $this->actingAs($usuario)
        ->get(route('pacientes.salud-ocular.index', $paciente))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('ocular/Index')
            ->has('prescripciones', 2)
            ->where('prescripciones.0.fechaVisible', '15/03/2026')
            ->has('prescripciones.0.ojos.od')
            ->has('prescripciones.0.ojos.oi')
            ->has('tipos', 5)
        );
});

it('arma el resumen del ojo con signo y dos decimales', function (): void {
    [$usuario, $paciente] = fichaOcular();
    PrescripcionOcular::factory()->for($paciente)->conOjos(
        od: ['esfera' => '2', 'cilindro' => '-0.5', 'eje' => '90', 'adicion' => '2.00'],
    )->create();

    $this->actingAs($usuario)
        ->get(route('pacientes.salud-ocular.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->where('prescripciones.0.ojos.od.esferaVisible', '+2,00')
            ->where('prescripciones.0.ojos.od.resumen', '+2,00  -0,50 x 90°  Add +2,00')
            ->where('prescripciones.0.ojos.od.sinDatos', false)
        );
});

it('dice que un ojo está sin datos en vez de inventar un cero', function (): void {
    [$usuario, $paciente] = fichaOcular();
    $receta = PrescripcionOcular::factory()->for($paciente)->create();
    $receta->graduacionDe(Ojo::Izquierdo)->update([
        'esfera' => null, 'cilindro' => null, 'eje' => null,
    ]);

    $this->actingAs($usuario)
        ->get(route('pacientes.salud-ocular.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->where('prescripciones.0.ojos.oi.sinDatos', true)
            ->where('prescripciones.0.ojos.oi.resumen', null)
        );
});

it('nunca convierte un texto en un cero al pedir el número', function (): void {
    $graduacion = new GraduacionOcular(['esfera' => 'no corrige', 'eje' => '']);

    expect($graduacion->numero('esfera'))->toBeNull()
        ->and($graduacion->numero('eje'))->toBeNull()
        // Y un 0 de verdad sí es un número: un eje 0 existe.
        ->and((new GraduacionOcular(['eje' => '0']))->numero('eje'))->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| El papel de la receta: sexto dueño de archivos
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    Storage::fake('local');
});

function papelDeReceta(): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($ruta, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

    return new UploadedFile($ruta, 'receta.pdf', 'application/pdf', null, true);
}

it('sube el papel de la receta y lo guarda en la carpeta del modelo', function (): void {
    [$usuario, $paciente] = fichaOcular();
    $receta = PrescripcionOcular::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->post(route('salud-ocular.adjuntos.store', $receta), [
            'archivos' => [papelDeReceta()],
            'tipo' => TipoAdjunto::PrescripcionOcular->value,
        ])
        ->assertRedirect()
        ->assertSessionHas('exito');

    expect($receta->adjuntos()->count())->toBe(1)
        ->and($receta->adjuntos()->first()->ruta)
        ->toStartWith("prescripciones_oculares/{$receta->id}/")
        // El paciente no se lo lleva: cuelga de la receta.
        ->and($paciente->adjuntos()->count())->toBe(0);
});

it('los otros cinco dueños de archivos siguen guardando donde siempre', function (): void {
    /*
     * El sexto dueño no tocó el cuerpo compartido (`guardarEn`); este test
     * cuida que tampoco haya movido de carpeta lo que ya estaba guardado.
     */
    $paciente = Paciente::factory()->create();
    $orden = OrdenEstudio::factory()->for($paciente)->create();
    $estudio = Estudio::factory()->for($paciente)->create();

    expect($paciente->carpetaDeArchivos())->toBe("pacientes/{$paciente->id}")
        ->and($orden->carpetaDeArchivos())->toBe("ordenes_estudio/{$orden->id}")
        ->and($estudio->carpetaDeArchivos())->toBe("estudios/{$estudio->id}");
});

it('un lector NO puede subir el papel', function (): void {
    [, $paciente] = fichaOcular();
    $receta = PrescripcionOcular::factory()->for($paciente)->create();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->post(route('salud-ocular.adjuntos.store', $receta), [
            'archivos' => [papelDeReceta()],
            'tipo' => TipoAdjunto::PrescripcionOcular->value,
        ])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Autorización y borrado
|--------------------------------------------------------------------------
*/

it('alguien ajeno a la ficha no la ve ni le escribe', function (): void {
    [, $paciente] = fichaOcular();
    $receta = PrescripcionOcular::factory()->for($paciente)->create();
    $ajeno = User::factory()->create();

    $this->actingAs($ajeno)
        ->get(route('pacientes.salud-ocular.index', $paciente))
        ->assertForbidden();

    $this->actingAs($ajeno)
        ->put(route('salud-ocular.update', $receta), recetaValida())
        ->assertForbidden();
});

it('un lector ve las recetas pero no las carga ni las borra', function (): void {
    [, $paciente] = fichaOcular();
    $receta = PrescripcionOcular::factory()->for($paciente)->create();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->get(route('pacientes.salud-ocular.index', $paciente))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('paciente.puedeEditar', false));

    $this->actingAs($lector)
        ->post(route('pacientes.salud-ocular.store', $paciente), recetaValida())
        ->assertForbidden();

    $this->actingAs($lector)
        ->delete(route('salud-ocular.destroy', $receta))
        ->assertForbidden();
});

it('un cuidador sí puede cargar y corregir', function (): void {
    [, $paciente] = fichaOcular();
    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->actingAs($cuidador)
        ->post(route('pacientes.salud-ocular.store', $paciente), recetaValida())
        ->assertSessionHas('exito');
});

it('borra la receta con sus dos ojos y la devuelve entera al restaurarla', function (): void {
    /*
     * ⚠️ El `cascadeOnDelete` de MySQL NO dispara con un soft delete, y acá
     * eso es lo correcto: las graduaciones siguen existiendo, así que
     * restaurar la receta la devuelve completa en vez de con los dos ojos
     * vacíos.
     */
    [$usuario, $paciente] = fichaOcular();
    $receta = PrescripcionOcular::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->delete(route('salud-ocular.destroy', $receta))
        ->assertSessionHas('exito');

    expect(PrescripcionOcular::count())->toBe(0)
        ->and($receta->graduaciones()->count())->toBe(2);

    $receta->restore();

    expect($receta->fresh()->graduaciones()->count())->toBe(2);
});

it('no deja tocar la receta de otra ficha desde una ruta de esta', function (): void {
    [$usuario] = fichaOcular();
    $ajena = PrescripcionOcular::factory()->create();

    $this->actingAs($usuario)
        ->delete(route('salud-ocular.destroy', $ajena))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| formatearDioptria: el signo y el cero
|--------------------------------------------------------------------------
*/

it('la dioptría lleva signo, salvo el cero', function (): void {
    expect(GraduacionOcular::formatearDioptria(2.0))->toBe('+2,00')
        ->and(GraduacionOcular::formatearDioptria(-1.25))->toBe('-1,25')
        ->and(GraduacionOcular::formatearDioptria(0.0))->toBe('0,00')
        // -0.0 es un cero igual: "-0,00" confundiría con una dirección.
        ->and(GraduacionOcular::formatearDioptria(-0.0))->toBe('0,00');
});

/*
|--------------------------------------------------------------------------
| Evolución de la esfera (paso 10.4)
|--------------------------------------------------------------------------
*/

it('arma la evolución de cada ojo por separado', function (): void {
    [$usuario, $paciente] = fichaOcular();
    PrescripcionOcular::factory()->for($paciente)->conOjos(
        od: ['esfera' => '-1.00'],
        oi: ['esfera' => '-1.25'],
    )->create(['fecha' => '2024-01-10']);
    PrescripcionOcular::factory()->for($paciente)->conOjos(
        od: ['esfera' => '-1.50'],
        oi: ['esfera' => '-1.75'],
    )->create(['fecha' => '2026-03-20']);

    $this->actingAs($usuario)
        ->get(route('pacientes.salud-ocular.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->has('evolucion', 2)
            ->where('evolucion.0.ojo', 'od')
            ->has('evolucion.0.puntos', 2)
            // json_encode(-1.0) sin JSON_PRESERVE_ZERO_FRACTION emite -1, no
            // -1.0: assertInertia compara estricto, así que el literal sin
            // parte decimal va como entero (misma trampa de la Etapa 9).
            ->where('evolucion.0.puntos.0.valor', -1)
            ->where('evolucion.0.puntos.1.valor', -1.5)
            ->where('evolucion.0.resumen.cantidad', 2)
            ->where('evolucion.1.ojo', 'oi')
            ->where('evolucion.1.puntos.0.valor', -1.25)
        );
});

it('un ojo sin corrección no rompe la evolución del otro', function (): void {
    /*
     * Es justo el caso que obliga a dos series independientes y no una con
     * OD de principal y OI de secundario: acá el OI no tiene esfera en
     * ninguna receta -`GraficoEvolucion` no admite un `valor` nulo-.
     *
     * `conOjos()` sin pasar `oi:` deja el ojo izquierdo con el default de
     * la factory -que SÍ trae esfera-, así que acá hay que anularla a mano
     * para probar el caso real: un ojo sin ninguna corrección.
     */
    [$usuario, $paciente] = fichaOcular();
    PrescripcionOcular::factory()->for($paciente)->conOjos(
        od: ['esfera' => '-1.00'],
        oi: ['esfera' => null, 'cilindro' => null, 'eje' => null],
    )->create(['fecha' => '2024-01-10']);
    PrescripcionOcular::factory()->for($paciente)->conOjos(
        od: ['esfera' => '-1.50'],
        oi: ['esfera' => null, 'cilindro' => null, 'eje' => null],
    )->create(['fecha' => '2026-03-20']);

    $this->actingAs($usuario)
        ->get(route('pacientes.salud-ocular.index', $paciente))
        ->assertInertia(fn ($p) => $p
            ->has('evolucion', 1)
            ->where('evolucion.0.ojo', 'od')
        );
});

it('con una sola receta no hay evolución que graficar', function (): void {
    [$usuario, $paciente] = fichaOcular();
    PrescripcionOcular::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->get(route('pacientes.salud-ocular.index', $paciente))
        ->assertInertia(fn ($p) => $p->where('evolucion', []));
});
