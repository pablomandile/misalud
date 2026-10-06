<?php

declare(strict_types=1);

use App\Enums\TipoAdjunto;
use App\Models\Adjunto;
use App\Models\Centro;
use App\Models\Estudio;
use App\Models\Medicamento;
use App\Models\Medicion;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\TipoMedicion;
use App\Models\Tratamiento;
use App\Models\Turno;
use App\Models\User;
use App\Models\Vacuna;
use App\Services\ArchivoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Si algo lo usa no se borra; si nada lo usa, se borra de verdad
|--------------------------------------------------------------------------
|
| La regla de los cinco catálogos. Arregla un 500 medido: borrar "Dr. Pérez" y
| volver a cargarlo reventaba, porque el borrado dejaba la fila en la papelera
| ocupando su `nombre_hash` en el UNIQUE.
|
*/

beforeEach(function (): void {
    Storage::fake('local');

    $this->usuario = User::factory()->create();
    $this->paciente = Paciente::factory()->for($this->usuario, 'usuario')->create();
});

it('⚠️ borrar un médico y volver a cargarlo con el mismo nombre ya no da 500', function (): void {
    $this->actingAs($this->usuario)->post(route('medicos.store'), ['nombre' => 'Dr. Pérez']);
    $this->actingAs($this->usuario)->delete(route('medicos.destroy', Medico::sole()));

    $this->actingAs($this->usuario)
        ->post(route('medicos.store'), ['nombre' => 'Dr. Pérez'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(Medico::withTrashed()->count())->toBe(1);
});

it('lo mismo con un centro y con una vacuna', function (): void {
    $centro = ['nombre' => 'Clínica Sur', 'tipo' => 'clinica'];
    $this->actingAs($this->usuario)->post(route('centros.store'), $centro)->assertSessionHasNoErrors();
    $this->actingAs($this->usuario)->delete(route('centros.destroy', Centro::sole()));
    $this->actingAs($this->usuario)
        ->post(route('centros.store'), $centro)
        ->assertSessionHasNoErrors();

    $this->actingAs($this->usuario)->post(route('vacunas.store'), ['nombre' => 'Antigripal']);
    $propia = Vacuna::query()->whereNotNull('usuario_id')->sole();
    $this->actingAs($this->usuario)->delete(route('vacunas.destroy', $propia));
    $this->actingAs($this->usuario)
        ->post(route('vacunas.store'), ['nombre' => 'Antigripal'])
        ->assertSessionHasNoErrors();
});

it('un médico que nada usa se borra de VERDAD, sin papelera', function (): void {
    $medico = Medico::factory()->for($this->usuario, 'usuario')->create();

    $this->actingAs($this->usuario)
        ->delete(route('medicos.destroy', $medico))
        ->assertSessionHas('exito');

    expect(DB::table('medicos')->count())->toBe(0);
});

it('⚠️ un médico que figura en un estudio NO se borra, y el aviso dice dónde', function (): void {
    $medico = Medico::factory()->for($this->usuario, 'usuario')->create(['nombre' => 'Dr. Pérez']);
    Estudio::factory()->for($this->paciente)->create(['medico_id' => $medico->id]);

    $this->actingAs($this->usuario)
        ->delete(route('medicos.destroy', $medico))
        ->assertSessionHas('error', 'No se puede eliminar Dr. Pérez: lo usa 1 estudio.');

    expect($medico->fresh())->not->toBeNull()
        ->and($medico->fresh()->trashed())->toBeFalse();
});

it('el aviso enumera todos los lugares, en plural y con "y"', function (): void {
    $medico = Medico::factory()->for($this->usuario, 'usuario')->create(['nombre' => 'Dr. Pérez']);
    Estudio::factory()->count(2)->for($this->paciente)->create(['medico_id' => $medico->id]);
    Turno::factory()->for($this->paciente)->create(['medico_id' => $medico->id]);

    $this->actingAs($this->usuario)
        ->delete(route('medicos.destroy', $medico))
        ->assertSessionHas('error', 'No se puede eliminar Dr. Pérez: lo usan 2 estudios y 1 turno.');
});

it('⚠️ un estudio en la PAPELERA también frena el borrado, y el aviso lo explica', function (): void {
    /*
     * Ese estudio se puede restaurar, y volvería sin médico. La persona no lo ve
     * en pantalla, así que el aviso tiene que decir por qué cuenta.
     */
    $medico = Medico::factory()->for($this->usuario, 'usuario')->create(['nombre' => 'Dr. Pérez']);
    Estudio::factory()->for($this->paciente)->create(['medico_id' => $medico->id])->delete();

    $this->actingAs($this->usuario)
        ->delete(route('medicos.destroy', $medico))
        ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'lo usa 1 estudio')
            && str_contains($m, 'todavía se pueden recuperar'));

    expect($medico->fresh())->not->toBeNull();
});

it('⚠️ un medicamento con un tratamiento en la papelera no se borra, en vez de dar 500', function (): void {
    /*
     * `tratamientos.medicamento_id` es `restrictOnDelete`: la base rechazaría el
     * borrado por esa fila que nadie ve. Sin contar la papelera, esto era un 500.
     */
    $medicamento = Medicamento::factory()->for($this->usuario, 'usuario')->create();
    Tratamiento::factory()->for($this->paciente)->create(['medicamento_id' => $medicamento->id])->delete();

    $this->actingAs($this->usuario)
        ->delete(route('medicamentos.destroy', $medicamento))
        ->assertSessionHas('error');

    expect($medicamento->fresh())->not->toBeNull();
});

it('una variable con mediciones no se borra', function (): void {
    $tipo = TipoMedicion::factory()->for($this->usuario, 'usuario')->create(['nombre' => 'Peso propio']);
    Medicion::factory()->for($this->paciente)->create(['tipo_medicion_id' => $tipo->id]);

    $this->actingAs($this->usuario)
        ->delete(route('tipos-medicion.destroy', $tipo))
        ->assertSessionHas('error', 'No se puede eliminar Peso propio: lo usa 1 medición.');
});

it('que un médico atienda en un centro NO frena el borrado: el vínculo se va con él', function (): void {
    $medico = Medico::factory()->for($this->usuario, 'usuario')->create();
    $centro = Centro::factory()->for($this->usuario, 'usuario')->create();
    $centro->medicos()->attach($medico);

    $this->actingAs($this->usuario)
        ->delete(route('medicos.destroy', $medico))
        ->assertSessionHas('exito');

    expect(DB::table('centro_medico')->count())->toBe(0)
        ->and($centro->fresh())->not->toBeNull();
});

it('⚠️ borrar un medicamento se lleva su prospecto: archivo del disco y fila', function (): void {
    /*
     * Sin esto, borrar de verdad dejaría el archivo cifrado en el disco sin nada
     * que lo referencie: invisible y para siempre. Incluye los prospectos que ya
     * estaban en la papelera, cuya fila seguía apuntando al medicamento.
     */
    $medicamento = Medicamento::factory()->for($this->usuario, 'usuario')->create();
    $archivos = app(ArchivoService::class);

    $pdf = "%PDF-1.4\n1 0 obj\n<</Type/Catalog>>\nendobj\ntrailer<</Root 1 0 R>>\n%%EOF\nprospecto";
    $vigente = $medicamento->adjuntos()->create([
        ...$archivos->guardarContenido($pdf, 'prospecto.pdf', $medicamento->carpetaDeArchivos()),
        'tipo' => TipoAdjunto::Prospecto,
    ]);
    $viejo = $medicamento->adjuntos()->create([
        ...$archivos->guardarContenido($pdf, 'viejo.pdf', $medicamento->carpetaDeArchivos()),
        'tipo' => TipoAdjunto::Prospecto,
    ]);
    $viejo->delete();

    $this->actingAs($this->usuario)
        ->delete(route('medicamentos.destroy', $medicamento))
        ->assertSessionHas('exito');

    expect(Storage::disk('local')->exists($vigente->ruta))->toBeFalse()
        ->and(Storage::disk('local')->exists($viejo->ruta))->toBeFalse()
        ->and(Adjunto::withTrashed()->count())->toBe(0)
        ->and(DB::table('medicamentos')->count())->toBe(0);
});

it('⚠️ autoriza ANTES de mirar los usos: a un extraño no le cuenta si hay datos', function (): void {
    $ajeno = Medico::factory()->create();
    Estudio::factory()->create(['medico_id' => $ajeno->id]);

    $this->actingAs($this->usuario)
        ->delete(route('medicos.destroy', $ajeno))
        ->assertForbidden();
});

it('una semilla compartida sigue sin poder borrarse', function (): void {
    $semilla = Vacuna::factory()->create(['usuario_id' => null]);

    $this->actingAs($this->usuario)
        ->delete(route('vacunas.destroy', $semilla))
        ->assertForbidden();

    expect($semilla->fresh())->not->toBeNull();
});
