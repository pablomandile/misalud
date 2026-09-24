<?php

declare(strict_types=1);

use App\Console\Commands\CerrarTratamientosVencidos;
use App\Models\Tratamiento;

it('cierra un tratamiento activo cuyo fin ya pasó', function (): void {
    $tratamiento = Tratamiento::factory()->create([
        'activo' => true,
        'inicio' => now()->subDays(30),
        'fin' => now()->subDays(5),
    ]);

    $this->artisan(CerrarTratamientosVencidos::class)->assertSuccessful();

    expect($tratamiento->fresh()->activo)->toBeFalse();
});

it('NO toca un tratamiento activo sin fecha de fin', function (): void {
    // Un tratamiento crónico, sin fin previsto, no puede vencer nunca.
    $tratamiento = Tratamiento::factory()->create([
        'activo' => true,
        'fin' => null,
    ]);

    $this->artisan(CerrarTratamientosVencidos::class);

    expect($tratamiento->fresh()->activo)->toBeTrue();
});

it('NO toca un tratamiento cuyo fin es hoy', function (): void {
    // "Vencido" es fin < hoy, no fin <= hoy: el último día sigue vigente.
    $tratamiento = Tratamiento::factory()->create([
        'activo' => true,
        'inicio' => now()->subDays(10),
        'fin' => now(),
    ]);

    $this->artisan(CerrarTratamientosVencidos::class);

    expect($tratamiento->fresh()->activo)->toBeTrue();
});

it('NO toca un tratamiento cuyo fin es futuro', function (): void {
    $tratamiento = Tratamiento::factory()->create([
        'activo' => true,
        'fin' => now()->addDays(5),
    ]);

    $this->artisan(CerrarTratamientosVencidos::class);

    expect($tratamiento->fresh()->activo)->toBeTrue();
});

it('NO toca un tratamiento que ya estaba inactivo', function (): void {
    // Reactivarlo es una acción manual: este comando nunca pone activo en
    // true, solo en false.
    $tratamiento = Tratamiento::factory()->inactivo()->create([
        'fin' => now()->subDays(60),
    ]);
    $actualizadoAntes = $tratamiento->updated_at;

    $this->artisan(CerrarTratamientosVencidos::class);

    expect($tratamiento->fresh()->activo)->toBeFalse()
        ->and($tratamiento->fresh()->updated_at)->toEqual($actualizadoAntes);
});

it('correrlo dos veces es igual de seguro que una', function (): void {
    $tratamiento = Tratamiento::factory()->create([
        'activo' => true,
        'inicio' => now()->subDays(30),
        'fin' => now()->subDays(5),
    ]);

    $this->artisan(CerrarTratamientosVencidos::class);
    $this->artisan(CerrarTratamientosVencidos::class);

    expect($tratamiento->fresh()->activo)->toBeFalse();
});

it('cierra varios de una vez', function (): void {
    Tratamiento::factory()->count(3)->create([
        'activo' => true,
        'inicio' => now()->subDays(30),
        'fin' => now()->subDays(1),
    ]);
    Tratamiento::factory()->create(['activo' => true, 'fin' => null]);

    $this->artisan(CerrarTratamientosVencidos::class);

    expect(Tratamiento::where('activo', true)->count())->toBe(1)
        ->and(Tratamiento::where('activo', false)->count())->toBe(3);
});
