<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Models\EtapaProcedimiento;
use App\Models\Medicamento;
use App\Models\Paciente;
use App\Models\PasoPedido;
use App\Models\PedidoProcedimiento;
use App\Models\Tratamiento;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| El procedimiento de pedido de un medicamento: etapas, avance e historial
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    // Mediodía en Argentina: lejos de la medianoche, donde las fechas de calendario se corren.
    Carbon::setTestNow('2026-11-20 15:00:00');
});

/**
 * @return array{0: User, 1: Paciente, 2: Tratamiento}
 */
function fichaConProcedimiento(int $etapas = 3): array
{
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Marta']);
    $tratamiento = Tratamiento::factory()->create([
        'paciente_id' => $paciente->id,
        'medicamento_id' => Medicamento::factory()->for($usuario, 'usuario')->create(['nombre_comercial' => 'Losartán'])->id,
        'activo' => true,
    ]);

    foreach (array_slice(['Pedir la receta', 'Autorizar en la obra social', 'Retirar en la farmacia'], 0, $etapas) as $orden => $nombre) {
        $tratamiento->etapasProcedimiento()->create(['orden' => $orden, 'nombre' => $nombre]);
    }

    return [$usuario, $paciente, $tratamiento];
}

/**
 * Los pasos tal como los manda el formulario: uno por etapa, en orden.
 *
 * @param  array<int, array<string, mixed>>  $porEtapa  índice de la etapa => datos
 * @return list<array<string, mixed>>
 */
function pasosDe(Tratamiento $tratamiento, array $porEtapa = []): array
{
    return $tratamiento->etapasProcedimiento()->get()->values()
        ->map(fn (EtapaProcedimiento $e, int $i): array => [
            'etapa_id' => $e->id,
            'completada' => '0',
            'completada_el' => '',
            'observaciones' => '',
            ...($porEtapa[$i] ?? []),
        ])->all();
}

// ---------------------------------------------------------------------------
// Las etapas
// ---------------------------------------------------------------------------

it('crea, renombra, reordena y saca etapas con la lista entera', function (): void {
    [$usuario, , $tratamiento] = fichaConProcedimiento();
    [$receta, $obraSocial, $farmacia] = $tratamiento->etapasProcedimiento()->get()->all();

    $this->actingAs($usuario)
        ->put(route('procedimiento.update', $tratamiento), ['etapas' => [
            ['id' => $farmacia->id, 'nombre' => 'Retirarlo en la farmacia'],
            ['id' => $receta->id, 'nombre' => 'Pedir la receta'],
            ['nombre' => 'Avisar a la familia'],
        ]])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('exito', 'Se guardaron las etapas.');

    expect($tratamiento->etapasProcedimiento()->get()->map(fn (EtapaProcedimiento $e): string => $e->nombre)->all())
        ->toBe(['Retirarlo en la farmacia', 'Pedir la receta', 'Avisar a la familia'])
        ->and(EtapaProcedimiento::find($obraSocial->id))->toBeNull();

    // El nombre queda cifrado.
    $crudo = DB::table('etapas_procedimiento')->where('id', $receta->id)->value('nombre');
    expect($crudo)->not->toContain('receta');
});

it('no deja renombrar desde acá la etapa de otro medicamento', function (): void {
    [$usuario, , $tratamiento] = fichaConProcedimiento();
    $ajena = EtapaProcedimiento::factory()->create(['nombre' => 'Ajena']);

    $this->actingAs($usuario)
        ->put(route('procedimiento.update', $tratamiento), ['etapas' => [
            ['id' => $ajena->id, 'nombre' => 'Mía'],
        ]])
        ->assertSessionHasErrors('etapas.0.id');

    expect($ajena->fresh()?->nombre)->toBe('Ajena');
});

it('pide el nombre de cada etapa', function (): void {
    [$usuario, , $tratamiento] = fichaConProcedimiento();

    $this->actingAs($usuario)
        ->put(route('procedimiento.update', $tratamiento), ['etapas' => [['nombre' => '  ']]])
        ->assertSessionHasErrors(['etapas.0.nombre' => 'Escribí el nombre de la etapa.']);
});

it('un lector y un extraño no tocan el procedimiento ni el avance', function (): void {
    [, $paciente, $tratamiento] = fichaConProcedimiento();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);
    $extranio = User::factory()->create();

    foreach ([$lector, $extranio] as $quien) {
        $this->actingAs($quien)
            ->put(route('procedimiento.update', $tratamiento), ['etapas' => []])
            ->assertForbidden();
        $this->actingAs($quien)
            ->put(route('pedido-en-curso.update', $tratamiento), ['iniciado_el' => '2026-11-10', 'pasos' => pasosDe($tratamiento)])
            ->assertForbidden();
    }

    expect($tratamiento->etapasProcedimiento()->count())->toBe(3)
        ->and(PedidoProcedimiento::count())->toBe(0);
});

it('un cuidador sí lleva el seguimiento', function (): void {
    [, $paciente, $tratamiento] = fichaConProcedimiento();
    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $this->actingAs($cuidador)
        ->put(route('pedido-en-curso.update', $tratamiento), ['iniciado_el' => '2026-11-10', 'pasos' => pasosDe($tratamiento)])
        ->assertSessionHasNoErrors();

    expect(PedidoProcedimiento::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// El avance
// ---------------------------------------------------------------------------

it('la primera vez que se guarda crea el pedido en curso, y la casilla acepta "on"', function (): void {
    [$usuario, , $tratamiento] = fichaConProcedimiento();

    $this->actingAs($usuario)
        ->put(route('pedido-en-curso.update', $tratamiento), [
            'iniciado_el' => '2026-11-10',
            'pasos' => pasosDe($tratamiento, [
                0 => ['completada' => 'on', 'completada_el' => '2026-11-12', 'observaciones' => 'La pidió por WhatsApp'],
                1 => ['observaciones' => 'Esperando'],
            ]),
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('exito', 'Se guardó el avance.');

    $pedido = PedidoProcedimiento::sole();
    expect($pedido->enCurso())->toBeTrue()
        ->and($pedido->iniciado_el->format('Y-m-d'))->toBe('2026-11-10');

    $pasos = $pedido->pasos()->get();
    expect($pasos)->toHaveCount(3)
        ->and($pasos[0]->completada)->toBeTrue()
        ->and($pasos[0]->completada_el?->format('Y-m-d'))->toBe('2026-11-12')
        ->and($pasos[0]->observaciones)->toBe('La pidió por WhatsApp')
        ->and($pasos[1]->completada)->toBeFalse()
        ->and($pasos[1]->observaciones)->toBe('Esperando')
        ->and($pasos[2]->observaciones)->toBeNull();

    // Las observaciones quedan cifradas.
    expect(DB::table('pasos_pedido')->where('id', $pasos[0]->id)->value('observaciones'))->not->toContain('WhatsApp');

    // Guardar otra vez actualiza el mismo pedido, no arma otro.
    $this->actingAs($usuario)
        ->put(route('pedido-en-curso.update', $tratamiento), ['iniciado_el' => '2026-11-11', 'pasos' => pasosDe($tratamiento)])
        ->assertSessionHasNoErrors();
    expect(PedidoProcedimiento::count())->toBe(1)
        ->and(PasoPedido::count())->toBe(3);
});

it('una etapa completada pide su fecha, que no puede ser anterior al inicio ni futura', function (string $fecha, string $error): void {
    [$usuario, , $tratamiento] = fichaConProcedimiento();

    $this->actingAs($usuario)
        ->put(route('pedido-en-curso.update', $tratamiento), [
            'iniciado_el' => '2026-11-10',
            'pasos' => pasosDe($tratamiento, [0 => ['completada' => '1', 'completada_el' => $fecha]]),
        ])
        ->assertSessionHasErrors(['pasos.0.completada_el' => $error]);

    expect(PedidoProcedimiento::count())->toBe(0);
})->with([
    'sin fecha' => ['', 'Elegí la fecha en que terminó esta etapa.'],
    'antes del inicio' => ['2026-11-09', 'No puede terminar antes de que empiece el pedido.'],
    'futura' => ['2026-11-21', 'La fecha no puede ser futura.'],
]);

it('el inicio del pedido no puede ser futuro', function (): void {
    [$usuario, , $tratamiento] = fichaConProcedimiento();

    $this->actingAs($usuario)
        ->put(route('pedido-en-curso.update', $tratamiento), ['iniciado_el' => '2026-11-21', 'pasos' => pasosDe($tratamiento)])
        ->assertSessionHasErrors(['iniciado_el' => 'La fecha no puede ser futura.']);
});

it('sin tildar no guarda la fecha, aunque llegue', function (): void {
    [$usuario, , $tratamiento] = fichaConProcedimiento();

    $this->actingAs($usuario)
        ->put(route('pedido-en-curso.update', $tratamiento), [
            'iniciado_el' => '2026-11-10',
            'pasos' => pasosDe($tratamiento, [0 => ['completada' => '0', 'completada_el' => '2026-11-12']]),
        ])
        ->assertSessionHasNoErrors();

    expect(PasoPedido::query()->orderBy('orden')->first()?->completada_el)->toBeNull();
});

it('sin etapas no hay avance que guardar', function (): void {
    [$usuario, , $tratamiento] = fichaConProcedimiento(0);

    $this->actingAs($usuario)
        ->put(route('pedido-en-curso.update', $tratamiento), ['iniciado_el' => '2026-11-10', 'pasos' => []])
        ->assertSessionHasErrors(['pasos' => 'Primero armá las etapas del procedimiento.']);
});

// ---------------------------------------------------------------------------
// El cierre y el historial
// ---------------------------------------------------------------------------

it('con todas las etapas completadas, el pedido pasa al historial y el formulario vuelve en blanco', function (): void {
    [$usuario, $paciente, $tratamiento] = fichaConProcedimiento();

    $this->actingAs($usuario)
        ->put(route('pedido-en-curso.update', $tratamiento), [
            'iniciado_el' => '2026-11-10',
            'pasos' => pasosDe($tratamiento, [
                0 => ['completada' => '1', 'completada_el' => '2026-11-11'],
                1 => ['completada' => '1', 'completada_el' => '2026-11-15', 'observaciones' => 'Pidieron la receta original'],
                2 => ['completada' => '1', 'completada_el' => '2026-11-13'],
            ]),
        ])
        ->assertSessionHas('exito', 'Pedido completo: quedó en el historial.');

    $pedido = PedidoProcedimiento::sole();
    expect($pedido->completado_el?->format('Y-m-d'))->toBe('2026-11-15');

    $this->actingAs($usuario)
        ->get(route('pedidos.index', ['paciente' => $paciente->id, 'solapa' => 'procedimiento']))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('procedimiento.enCurso', null)
            ->where('procedimiento.pasos.0.completada', false)
            ->where('procedimiento.pasos.1.observaciones', '')
            ->has('procedimiento.historial', 1)
            ->where('procedimiento.historial.0.inicioVisible', '10/11/2026')
            ->where('procedimiento.historial.0.finVisible', '15/11/2026')
            ->where('procedimiento.historial.0.pasos.1.observaciones', 'Pidieron la receta original')
            ->where('procedimiento.reabrible', $pedido->id));
});

it('renombrar o sacar una etapa después no cambia el historial', function (): void {
    [$usuario, $paciente, $tratamiento] = fichaConProcedimiento(2);
    $this->actingAs($usuario)
        ->put(route('pedido-en-curso.update', $tratamiento), [
            'iniciado_el' => '2026-11-10',
            'pasos' => pasosDe($tratamiento, [
                0 => ['completada' => '1', 'completada_el' => '2026-11-11'],
                1 => ['completada' => '1', 'completada_el' => '2026-11-12'],
            ]),
        ]);

    [$receta] = $tratamiento->etapasProcedimiento()->get()->all();
    $this->actingAs($usuario)
        ->put(route('procedimiento.update', $tratamiento), ['etapas' => [
            ['id' => $receta->id, 'nombre' => 'Conseguir la receta'],
        ]])
        ->assertSessionHasNoErrors();

    $this->actingAs($usuario)
        ->get(route('pedidos.index', ['paciente' => $paciente->id, 'solapa' => 'procedimiento']))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('procedimiento.etapas', 1)
            ->where('procedimiento.historial.0.pasos.0.nombre', 'Pedir la receta')
            ->where('procedimiento.historial.0.pasos.1.nombre', 'Autorizar en la obra social'));
});

it('una etapa sumada a mitad del pedido aparece sin tildar y no lo cierra', function (): void {
    [$usuario, , $tratamiento] = fichaConProcedimiento(1);
    $this->actingAs($usuario)->put(route('pedido-en-curso.update', $tratamiento), [
        'iniciado_el' => '2026-11-10',
        'pasos' => pasosDe($tratamiento, [0 => ['observaciones' => 'Pedida']]),
    ]);

    $this->actingAs($usuario)->put(route('procedimiento.update', $tratamiento), ['etapas' => [
        ['id' => $tratamiento->etapasProcedimiento()->value('id'), 'nombre' => 'Pedir la receta'],
        ['nombre' => 'Retirar'],
    ]]);

    // Solo la primera completada: el pedido sigue en curso.
    $this->actingAs($usuario)->put(route('pedido-en-curso.update', $tratamiento), [
        'iniciado_el' => '2026-11-10',
        'pasos' => [pasosDe($tratamiento, [0 => ['completada' => '1', 'completada_el' => '2026-11-11']])[0]],
    ])->assertSessionHas('exito', 'Se guardó el avance.');

    expect(PedidoProcedimiento::sole()->enCurso())->toBeTrue()
        ->and(PasoPedido::count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Volver a abrir
// ---------------------------------------------------------------------------

it('vuelve a abrir el último pedido completado, pero no uno anterior ni si hay otro en curso', function (): void {
    [$usuario, , $tratamiento] = fichaConProcedimiento();
    $viejo = PedidoProcedimiento::factory()->for($tratamiento)->create(['iniciado_el' => '2026-09-01', 'completado_el' => '2026-09-10']);
    $ultimo = PedidoProcedimiento::factory()->for($tratamiento)->create(['iniciado_el' => '2026-10-01', 'completado_el' => '2026-10-10']);

    $this->actingAs($usuario)
        ->put(route('pedidos-procedimiento.reabrir', $viejo))
        ->assertSessionHas('error');
    expect($viejo->fresh()?->enCurso())->toBeFalse();

    $this->actingAs($usuario)
        ->put(route('pedidos-procedimiento.reabrir', $ultimo))
        ->assertSessionHas('exito', 'Se volvió a abrir el pedido.');
    expect($ultimo->fresh()?->enCurso())->toBeTrue();

    // Con uno en curso, ya no se abre otro.
    $ultimo->update(['completado_el' => '2026-10-10']);
    PedidoProcedimiento::factory()->for($tratamiento)->create(['iniciado_el' => '2026-11-01']);
    $this->actingAs($usuario)
        ->put(route('pedidos-procedimiento.reabrir', $ultimo))
        ->assertSessionHas('error');
});

it('un lector no vuelve a abrir un pedido', function (): void {
    [, $paciente, $tratamiento] = fichaConProcedimiento();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);
    $pedido = PedidoProcedimiento::factory()->for($tratamiento)->completado()->create();

    $this->actingAs($lector)
        ->put(route('pedidos-procedimiento.reabrir', $pedido))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// La pantalla
// ---------------------------------------------------------------------------

it('la solapa procedimiento elige por defecto el primer medicamento activo y manda solo ese', function (): void {
    [$usuario, $paciente, $tratamiento] = fichaConProcedimiento();
    Tratamiento::factory()->create([
        'paciente_id' => $paciente->id,
        'medicamento_id' => Medicamento::factory()->for($usuario, 'usuario')->create(['nombre_comercial' => 'Atenolol'])->id,
        'activo' => false,
    ]);

    $this->actingAs($usuario)
        ->get(route('pedidos.index', ['paciente' => $paciente->id, 'solapa' => 'procedimiento']))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('pedidos/Index')
            ->where('solapa', 'procedimiento')
            ->where('medicamentoElegido', $tratamiento->id)
            ->where('hoy', '2026-11-20')
            ->has('procedimiento.etapas', 3)
            ->where('procedimiento.etapas.0.nombre', 'Pedir la receta'));
});

it('la solapa de mensajes no arma el procedimiento', function (): void {
    [$usuario, $paciente] = fichaConProcedimiento();

    $this->actingAs($usuario)
        ->get(route('pedidos.index', ['paciente' => $paciente->id]))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('solapa', 'mensajes')
            ->where('procedimiento', null));
});

it('un medicamento de otra ficha da 404', function (): void {
    [$usuario, $paciente] = fichaConProcedimiento();
    $ajeno = Tratamiento::factory()->create();

    $this->actingAs($usuario)
        ->get(route('pedidos.index', ['paciente' => $paciente->id, 'solapa' => 'procedimiento', 'medicamento' => $ajeno->id]))
        ->assertNotFound();
});

it('borrar el medicamento de verdad se lleva su procedimiento y su historial', function (): void {
    [$usuario, , $tratamiento] = fichaConProcedimiento();
    $this->actingAs($usuario)->put(route('pedido-en-curso.update', $tratamiento), [
        'iniciado_el' => '2026-11-10',
        'pasos' => pasosDe($tratamiento),
    ]);

    $tratamiento->forceDelete();

    expect(EtapaProcedimiento::count())->toBe(0)
        ->and(PedidoProcedimiento::count())->toBe(0)
        ->and(PasoPedido::count())->toBe(0);
});

it('«Descargar mis datos» trae el procedimiento y cada pedido con sus pasos, descifrados', function (): void {
    [$usuario, , $tratamiento] = fichaConProcedimiento(1);
    $this->actingAs($usuario)->put(route('pedido-en-curso.update', $tratamiento), [
        'iniciado_el' => '2026-11-10',
        'pasos' => pasosDe($tratamiento, [0 => ['observaciones' => 'Llamé al 0800 de la obra social']]),
    ]);

    $respuesta = $this->actingAs($usuario)->get(route('exportacion.datos'));
    $zip = new ZipArchive;
    $zip->open($respuesta->baseResponse->getFile()->getPathname());
    $datos = json_decode((string) $zip->getFromName('datos.json'), true);
    $zip->close();

    $medicamento = $datos['fichas'][0]['tratamientos'][0];
    expect($medicamento['etapasProcedimiento'][0]['nombre'])->toBe('Pedir la receta')
        ->and($medicamento['pedidosProcedimiento'][0]['pasos'][0]['observaciones'])->toBe('Llamé al 0800 de la obra social');
});
