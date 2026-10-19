<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Enums\TipoAdjunto;
use App\Models\Adjunto;
use App\Models\DocumentoPorRevisar;
use App\Models\Paciente;
use App\Models\User;
use App\Services\AdjuntadorDeArchivos;
use App\Support\AdjuntoDeCasilla;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * Un documento por revisar con su PDF, como lo deja el buzón.
 *
 * @param  array{paciente_id?: int|null, usuario_id?: int|null}  $de
 */
function porRevisarCon(array $de): DocumentoPorRevisar
{
    $documento = DocumentoPorRevisar::factory()->create();
    $documento->forceFill($de)->save();

    app(AdjuntadorDeArchivos::class)->adjuntarContenidos(
        $documento,
        [new AdjuntoDeCasilla('receta.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n")],
        TipoAdjunto::Otro,
    );

    return $documento;
}

it('guardar en una ficha crea la receta y le pasa el archivo sin copiarlo', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $documento = porRevisarCon(['usuario_id' => $usuario->id]);
    $adjunto = $documento->adjuntos()->sole();

    $this->actingAs($usuario)
        ->post(route('por-revisar.guardar', $documento), [
            'paciente_id' => $paciente->id,
            'tipo' => 'receta',
            'texto' => 'Enalapril',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $receta = $paciente->recetasMedicas()->sole();
    $movido = Adjunto::findOrFail($adjunto->id);

    expect($receta->detalle)->toBe('Enalapril')
        ->and($movido->adjuntable_type)->toBe($receta->getMorphClass())
        ->and($movido->adjuntable_id)->toBe($receta->id)
        ->and($movido->tipo)->toBe(TipoAdjunto::Receta)
        ->and($movido->ruta)->toBe($adjunto->ruta)
        ->and(DocumentoPorRevisar::count())->toBe(0);
});

it('⚠️ no deja guardarlo en la ficha de otro', function (): void {
    $usuario = User::factory()->create();
    $documento = porRevisarCon(['usuario_id' => $usuario->id]);
    $ajena = Paciente::factory()->create();

    $this->actingAs($usuario)
        ->post(route('por-revisar.guardar', $documento), ['paciente_id' => $ajena->id, 'tipo' => 'receta'])
        ->assertSessionHasErrors('paciente_id');

    expect($ajena->recetasMedicas()->count())->toBe(0);
});

it('un lector de la ficha no lo ve ni lo resuelve', function (): void {
    $paciente = Paciente::factory()->create();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);
    $documento = porRevisarCon(['paciente_id' => $paciente->id]);

    $this->actingAs($lector)->get(route('casilla.index'))
        ->assertInertia(fn ($p) => $p->has('porRevisar', 0));

    $this->actingAs($lector)->post(route('por-revisar.guardar', $documento), ['paciente_id' => $paciente->id, 'tipo' => 'receta'])
        ->assertForbidden();
    $this->actingAs($lector)->delete(route('por-revisar.destroy', $documento))->assertForbidden();
    $this->actingAs($lector)->get(route('adjuntos.show', $documento->adjuntos()->sole()))->assertForbidden();
});

it('un cuidador de la ficha sí lo resuelve', function (): void {
    $paciente = Paciente::factory()->create();
    $cuidador = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);
    $documento = porRevisarCon(['paciente_id' => $paciente->id]);

    $this->actingAs($cuidador)->get(route('casilla.index'))
        ->assertInertia(fn ($p) => $p->has('porRevisar', 1));

    $this->actingAs($cuidador)
        ->post(route('por-revisar.guardar', $documento), ['paciente_id' => $paciente->id, 'tipo' => 'orden'])
        ->assertSessionHasNoErrors();

    expect($paciente->ordenesEstudio()->count())->toBe(1);
});

it('un extraño recibe 403 al resolver y al descartar', function (): void {
    $documento = porRevisarCon(['usuario_id' => User::factory()->create()->id]);
    $extranio = User::factory()->create();

    $this->actingAs($extranio)->post(route('por-revisar.guardar', $documento), ['paciente_id' => 1, 'tipo' => 'receta'])
        ->assertForbidden();
    $this->actingAs($extranio)->delete(route('por-revisar.destroy', $documento))->assertForbidden();
});

it('descartar borra el documento y su archivo del disco', function (): void {
    $usuario = User::factory()->create();
    $documento = porRevisarCon(['usuario_id' => $usuario->id]);
    $ruta = $documento->adjuntos()->sole()->ruta;

    $this->actingAs($usuario)->delete(route('por-revisar.destroy', $documento))->assertRedirect();

    expect(DocumentoPorRevisar::count())->toBe(0)
        ->and(Storage::disk('local')->exists($ruta))->toBeFalse();
});

it('la casilla muestra la dirección del buzón, los correos que reconoce y lo pendiente', function (): void {
    config(['misalud.buzon.direccion' => 'buzon@ejemplo.test']);
    $usuario = User::factory()->create(['email' => 'hija@ejemplo.test']);
    Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Marta', 'email' => 'marta@ejemplo.test']);
    porRevisarCon(['usuario_id' => $usuario->id]);

    $this->actingAs($usuario)->get(route('casilla.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('casilla/Index')
            ->where('direccion', 'buzon@ejemplo.test')
            ->where('tuCorreo', 'hija@ejemplo.test')
            ->where('fichas.0.email', 'marta@ejemplo.test')
            ->where('maximoMb', 12)
            ->has('porRevisar', 1)
            ->has('porRevisar.0.adjuntos', 1)
        );
});
