<?php

declare(strict_types=1);

use App\Enums\EstadoReceta;
use App\Enums\RolPaciente;
use App\Enums\TipoAdjunto;
use App\Models\Medicamento;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\RecetaMedica;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @return array{0: User, 1: Paciente}
 */
function fichaConRecetas(): array
{
    $usuario = User::factory()->create(['zona_horaria' => 'America/Argentina/Buenos_Aires']);
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();

    return [$usuario, $paciente];
}

function recetaPdfDePrueba(string $nombre = 'receta.pdf'): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($ruta, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

    return new UploadedFile($ruta, $nombre, 'application/pdf', null, true);
}

it('carga una receta con su PDF en el mismo alta', function (): void {
    [$usuario, $paciente] = fichaConRecetas();

    $this->actingAs($usuario)
        ->post(route('pacientes.recetas-medicas.store', $paciente), [
            'detalle' => 'Enalapril 10 mg, 2 cajas',
            'fecha' => $usuario->hoyCalendario()->toDateString(),
            'vigencia_dias' => '30',
            'archivos' => [recetaPdfDePrueba()],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $receta = $paciente->recetasMedicas()->sole();

    expect($receta->detalle)->toBe('Enalapril 10 mg, 2 cajas')
        ->and($receta->estado)->toBe(EstadoReceta::Disponible)
        ->and($receta->adjuntos()->sole()->tipo)->toBe(TipoAdjunto::Receta);
});

it('el PDF es opcional', function (): void {
    [$usuario, $paciente] = fichaConRecetas();

    $this->actingAs($usuario)
        ->post(route('pacientes.recetas-medicas.store', $paciente), [
            'detalle' => 'Levotiroxina 50 mcg',
            'fecha' => $usuario->hoyCalendario()->toDateString(),
            'vigencia_dias' => 30,
        ])
        ->assertSessionHasNoErrors();

    expect($paciente->recetasMedicas()->count())->toBe(1);
});

it('pide el medicamento o el texto, al menos uno', function (): void {
    [$usuario, $paciente] = fichaConRecetas();

    $this->actingAs($usuario)
        ->post(route('pacientes.recetas-medicas.store', $paciente), [
            'fecha' => $usuario->hoyCalendario()->toDateString(),
            'vigencia_dias' => 30,
        ])
        ->assertSessionHasErrors(['detalle', 'medicamento_id']);
});

it('acepta un medicamento del catálogo sin texto', function (): void {
    [$usuario, $paciente] = fichaConRecetas();
    $medicamento = Medicamento::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.recetas-medicas.store', $paciente), [
            'medicamento_id' => $medicamento->id,
            'fecha' => $usuario->hoyCalendario()->toDateString(),
            'vigencia_dias' => 30,
        ])
        ->assertSessionHasNoErrors();

    expect($paciente->recetasMedicas()->sole()->medicamento_id)->toBe($medicamento->id);
});

it('no deja elegir el médico del catálogo de otro', function (): void {
    [$usuario, $paciente] = fichaConRecetas();
    $ajeno = Medico::factory()->for(User::factory()->create(), 'usuario')->create();

    $this->actingAs($usuario)
        ->post(route('pacientes.recetas-medicas.store', $paciente), [
            'detalle' => 'Algo',
            'medico_id' => $ajeno->id,
            'fecha' => $usuario->hoyCalendario()->toDateString(),
            'vigencia_dias' => 30,
        ])
        ->assertSessionHasErrors('medico_id');
});

it('rechaza una receta con fecha futura y una vigencia imposible', function (): void {
    [$usuario, $paciente] = fichaConRecetas();

    $this->actingAs($usuario)
        ->post(route('pacientes.recetas-medicas.store', $paciente), [
            'detalle' => 'Algo',
            'fecha' => $usuario->hoyCalendario()->addDays(3)->toDateString(),
            'vigencia_dias' => 0,
        ])
        ->assertSessionHasErrors(['fecha', 'vigencia_dias']);
});

it('cifra el detalle y las notas', function (): void {
    [, $paciente] = fichaConRecetas();
    $receta = RecetaMedica::factory()->for($paciente)->create([
        'detalle' => 'Detalle-Distintivo',
        'notas' => 'Nota-Distintiva',
    ]);

    $fila = (array) DB::table('recetas_medicas')->where('id', $receta->id)->first();

    expect($fila['detalle'])->not->toContain('Detalle-Distintivo')
        ->and($fila['notas'])->not->toContain('Nota-Distintiva');
});

it('separa las que se pueden usar hoy, de la que vence primero', function (): void {
    [$usuario, $paciente] = fichaConRecetas();
    $hoy = $usuario->hoyCalendario();

    $tarde = RecetaMedica::factory()->for($paciente)->create(['fecha' => $hoy->subDays(2), 'vigencia_dias' => 30]);
    $pronto = RecetaMedica::factory()->for($paciente)->create(['fecha' => $hoy->subDays(28), 'vigencia_dias' => 30]);
    $vencida = RecetaMedica::factory()->for($paciente)->create(['fecha' => $hoy->subDays(40), 'vigencia_dias' => 30]);
    $usada = RecetaMedica::factory()->for($paciente)->usada()->create(['fecha' => $hoy]);

    $this->actingAs($usuario)
        ->get(route('pacientes.recetas-medicas.index', $paciente))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('recetas-medicas/Index')
            ->where('disponibles.0.id', $pronto->id)
            ->where('disponibles.1.id', $tarde->id)
            ->has('disponibles', 2)
            ->has('historial', 2)
            ->where('disponibles.0.venceTexto', 'Vence el '.$hoy->addDays(2)->format('d/m/Y').' (en 2 días)')
        );

    expect([$vencida->id, $usada->id])->each->toBeInt();
});

it('vence mañana se cuenta en días de calendario de la persona', function (): void {
    [$usuario, $paciente] = fichaConRecetas();
    $hoy = $usuario->hoyCalendario();
    RecetaMedica::factory()->for($paciente)->create(['fecha' => $hoy->subDays(29), 'vigencia_dias' => 30]);

    $this->actingAs($usuario)
        ->get(route('pacientes.recetas-medicas.index', $paciente))
        ->assertInertia(fn ($p) => $p->where('disponibles.0.venceTexto', 'Vence mañana'));
});

it('marcar usada y deshacerlo', function (): void {
    [$usuario, $paciente] = fichaConRecetas();
    $receta = RecetaMedica::factory()->for($paciente)->create();

    $this->actingAs($usuario)
        ->put(route('recetas-medicas.uso', $receta), ['usada' => '1'])
        ->assertSessionHas('exito');

    expect($receta->fresh()->estado)->toBe(EstadoReceta::Usada);

    $this->actingAs($usuario)
        ->put(route('recetas-medicas.uso', $receta), ['usada' => '0']);

    expect($receta->fresh()->estado)->toBe(EstadoReceta::Disponible);
});

it('editar no toca el estado', function (): void {
    [$usuario, $paciente] = fichaConRecetas();
    $receta = RecetaMedica::factory()->for($paciente)->usada()->create();

    $this->actingAs($usuario)
        ->put(route('recetas-medicas.update', $receta), [
            'detalle' => 'Corregido',
            'fecha' => $receta->fecha->toDateString(),
            'vigencia_dias' => 60,
            'estado' => 'disponible',
        ])
        ->assertSessionHasNoErrors();

    expect($receta->fresh()->detalle)->toBe('Corregido')
        ->and($receta->fresh()->vigencia_dias)->toBe(60)
        ->and($receta->fresh()->estado)->toBe(EstadoReceta::Usada);
});

it('un lector la ve pero no puede tocarla', function (): void {
    [, $paciente] = fichaConRecetas();
    $receta = RecetaMedica::factory()->for($paciente)->create();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)
        ->get(route('pacientes.recetas-medicas.index', $paciente))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('paciente.puedeEditar', false));

    $this->actingAs($lector)->put(route('recetas-medicas.uso', $receta), ['usada' => '1'])->assertForbidden();
    $this->actingAs($lector)->delete(route('recetas-medicas.destroy', $receta))->assertForbidden();
});

it('se ofrece para mandar junto con la credencial de la misma ficha', function (): void {
    [$usuario, $paciente] = fichaConRecetas();

    $this->actingAs($usuario)
        ->post(route('pacientes.recetas-medicas.store', $paciente), [
            'detalle' => 'Enalapril',
            'fecha' => $usuario->hoyCalendario()->toDateString(),
            'vigencia_dias' => 30,
            'archivos' => [recetaPdfDePrueba()],
        ]);

    $adjunto = $paciente->recetasMedicas()->sole()->adjuntos()->sole();

    $this->actingAs($usuario)
        ->get(route('envios.create', ['adjuntos' => [$adjunto->id]]))
        ->assertOk();
});
