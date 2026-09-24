<?php

declare(strict_types=1);

use App\Models\Medicamento;
use App\Models\TipoMedicion;
use App\Models\User;
use App\Models\Vacuna;
use Database\Seeders\CatalogosSeeder;

/*
|--------------------------------------------------------------------------
| Corre en producción: tiene que poder ejecutarse más de una vez
|--------------------------------------------------------------------------
*/

it('crea las semillas de medicamentos y vacunas', function (): void {
    $this->seed(CatalogosSeeder::class);

    expect(Medicamento::whereNull('usuario_id')->count())->toBeGreaterThan(0)
        ->and(Vacuna::whereNull('usuario_id')->count())->toBeGreaterThan(0)
        ->and(Medicamento::whereNull('usuario_id')->first()?->usuario_id)->toBeNull()
        ->and(Vacuna::whereNull('usuario_id')->first()?->usuario_id)->toBeNull();
});

it('correrlo dos veces NO duplica nada', function (): void {
    // El UNIQUE de la base no protege entre semillas -MySQL admite
    // cualquier cantidad de usuario_id NULL en un índice único-, así que
    // esto prueba la idempotencia que garantiza el seeder, no la base.
    $this->seed(CatalogosSeeder::class);
    $medicamentosTrasLaPrimera = Medicamento::count();
    $vacunasTrasLaPrimera = Vacuna::count();

    $this->seed(CatalogosSeeder::class);

    expect(Medicamento::count())->toBe($medicamentosTrasLaPrimera)
        ->and(Vacuna::count())->toBe($vacunasTrasLaPrimera);
});

it('no pisa un medicamento que el usuario ya tenía cargado con ese nombre', function (): void {
    // La semilla y lo propio de un usuario pueden coexistir con el mismo
    // nombre: el UNIQUE está acotado por usuario_id, y acá son NULL contra
    // un id real, así que no chocan entre sí.
    $usuario = User::factory()->create();
    Medicamento::factory()->for($usuario, 'usuario')->create(['nombre_comercial' => 'Paracetamol']);

    $this->seed(CatalogosSeeder::class);

    expect(Medicamento::where('usuario_id', $usuario->id)->count())->toBe(1)
        ->and(Medicamento::whereNull('usuario_id')->count())->toBeGreaterThan(0);
});

it('no crea una semilla duplicada si ya existía una con ese nombre', function (): void {
    Medicamento::factory()->semilla()->create(['nombre_comercial' => 'Paracetamol']);

    $this->seed(CatalogosSeeder::class);

    // `nombre_comercial` está cifrado: se filtra por su índice ciego, nunca
    // por la columna en sí (ver CLAUDE.md, `ConsultaVigilada`).
    expect(Medicamento::whereNull('usuario_id')->dondeIndiceCiego('nombre_comercial', 'Paracetamol')->count())->toBe(1);
});

it('las semillas se ven pero no se pueden editar ni borrar', function (): void {
    $this->seed(CatalogosSeeder::class);
    $totalDeSemillas = Medicamento::whereNull('usuario_id')->count();
    $semilla = Medicamento::whereNull('usuario_id')->dondeIndiceCiego('nombre_comercial', 'Paracetamol')->firstOrFail();
    $usuario = User::factory()->create();

    // Sin ningún medicamento propio, el listado de este usuario es
    // exactamente el de las semillas: se ven todas.
    $this->actingAs($usuario)
        ->get(route('medicamentos.index'))
        ->assertInertia(fn ($p) => $p->has('registros', $totalDeSemillas));

    $this->actingAs($usuario)
        ->put(route('medicamentos.update', $semilla), ['nombre_comercial' => 'Intento'])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Las variables: la semilla que hace que la app sirva sin configurar nada
|--------------------------------------------------------------------------
*/

it('siembra las variables de siempre, con la presión de dos valores', function (): void {
    $this->seed(CatalogosSeeder::class);

    $presion = TipoMedicion::whereNull('usuario_id')
        ->dondeIndiceCiego('nombre', 'Presión arterial')
        ->firstOrFail();

    expect(TipoMedicion::whereNull('usuario_id')->count())->toBe(7)
        ->and($presion->tieneValorSecundario())->toBeTrue()
        ->and($presion->etiqueta_principal)->toBe('Sistólica')
        ->and($presion->etiqueta_secundaria)->toBe('Diastólica')
        ->and($presion->min_normal)->toBe(90.0)
        ->and($presion->max_normal_secundario)->toBe(90.0);
});

it('el peso y la altura llevan su clave, para que el IMC las reconozca', function (): void {
    // `clave` no es fillable: la escribe solo el seeder, con forceFill.
    $this->seed(CatalogosSeeder::class);

    expect(TipoMedicion::where('clave', TipoMedicion::CLAVE_PESO)->count())->toBe(1)
        ->and(TipoMedicion::where('clave', TipoMedicion::CLAVE_ALTURA)->count())->toBe(1)
        ->and(TipoMedicion::whereNull('clave')->count())->toBe(5);
});

it('duplicar la semilla de peso se lleva la clave, y el IMC sigue andando', function (): void {
    $usuario = User::factory()->create();
    $this->seed(CatalogosSeeder::class);
    $semilla = TipoMedicion::where('clave', TipoMedicion::CLAVE_PESO)->firstOrFail();

    $this->actingAs($usuario)->post(route('tipos-medicion.duplicar', $semilla));

    expect(TipoMedicion::where('usuario_id', $usuario->id)->first()?->clave)
        ->toBe(TipoMedicion::CLAVE_PESO);
});

it('correrlo dos veces tampoco duplica las variables', function (): void {
    $this->seed(CatalogosSeeder::class);
    $this->seed(CatalogosSeeder::class);

    expect(TipoMedicion::count())->toBe(7);
});
