<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;

/** La versión del asset, o Inertia contesta 409 en vez de la página. */
function versionDeInertia(): string
{
    return (string) app(HandleInertiaRequests::class)->version(request());
}

it('prohíbe guardar la respuesta XHR de Inertia', function (): void {
    $usuario = User::factory()->create();

    $respuesta = $this->actingAs($usuario)->get('/dashboard', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => versionDeInertia(),
    ]);

    $respuesta->assertOk();
    expect($respuesta->headers->get('Content-Type'))->toContain('application/json');
    expect($respuesta->headers->get('Cache-Control'))->toContain('no-store');
    expect($respuesta->headers->get('Vary'))->toContain('X-Inertia');
});

it('deja cacheable el documento HTML, para no perder el bfcache', function (): void {
    $usuario = User::factory()->create();

    $respuesta = $this->actingAs($usuario)->get('/dashboard');

    expect($respuesta->headers->get('Content-Type'))->toContain('text/html');
    expect($respuesta->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('sin X-Inertia-Version correcto, Inertia contesta 409 y no rompe el header Vary', function (): void {
    $usuario = User::factory()->create();

    $respuesta = $this->actingAs($usuario)->get('/dashboard', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => 'version-vieja-que-no-coincide',
    ]);

    // El middleware de Inertia reemplaza la respuesta entera en 409, y el
    // Vary lo pone handle() DESPUÉS de llamar a parent::handle(): tiene que
    // seguir estando, o el 409 quedaría sin la cabecera que lo distingue.
    $respuesta->assertStatus(409);
    expect($respuesta->headers->get('Vary'))->toContain('X-Inertia');
});
