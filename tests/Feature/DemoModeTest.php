<?php

declare(strict_types=1);

use App\Models\Contacto;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Modo demo (APP_DEMO=true): la cuenta compartida no se toca y no salen mails
|--------------------------------------------------------------------------
|
| Todo actúa SOLO con el flag. Las pruebas de "sin el flag" son las que
| cuidan la productiva: el mismo código va a las dos copias.
*/

function encenderDemo(): void
{
    config(['demo.enabled' => true]);
}

it('sin el flag no cambia nada: el registro existe y el login no ofrece la demo', function (): void {
    $this->get(route('register'))->assertOk();

    $this->get(route('login'))
        ->assertInertia(fn (Assert $pagina) => $pagina->where('demo', null));
});

it('con el flag, el registro, Google y recuperar la clave mandan al login', function (string $ruta): void {
    encenderDemo();

    $this->get(route($ruta))->assertRedirect(route('login'));
})->with(['register', 'password.request', 'google.redirect']);

it('con el flag, crear una cuenta por POST tampoco anda', function (): void {
    encenderDemo();

    $this->post(route('register.store'), [
        'name' => 'Intruso',
        'email' => 'intruso@ejemplo.test',
        'password' => 'una-clave-larga-1234',
        'password_confirmation' => 'una-clave-larga-1234',
        'terminos' => '1',
    ])->assertRedirect(route('login'));

    expect(User::where('email', 'intruso@ejemplo.test')->exists())->toBeFalse();
});

it('con el flag, el login muestra los datos de acceso', function (): void {
    encenderDemo();

    $this->get(route('login'))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('demo.email', config('demo.email'))
            ->where('demo.password', config('demo.password')));
});

it('con el flag, la cuenta no se cambia ni se borra', function (): void {
    encenderDemo();
    $usuario = User::factory()->create(['name' => 'Ana']);

    $this->actingAs($usuario)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), ['name' => 'Otra', 'email' => 'otra@ejemplo.test'])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('error', 'En la demo no se puede cambiar la cuenta.');

    $this->actingAs($usuario)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHas('error');

    expect($usuario->fresh()?->name)->toBe('Ana');
});

it('con el flag, 2FA y passkeys se rechazan: dejarían afuera a todos los visitantes', function (): void {
    encenderDemo();
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('two-factor.enable'))
        ->assertForbidden();

    $this->actingAs($usuario)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson(route('passkey.registration-options'))
        ->assertForbidden();

    expect($usuario->fresh()?->two_factor_secret)->toBeNull();
});

it('con el flag, invitar a una ficha no manda ningún mail', function (): void {
    encenderDemo();
    Mail::fake();
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->create(['usuario_id' => $usuario->id]);

    $this->actingAs($usuario)
        ->post(route('pacientes.invitaciones.store', $paciente), ['email' => 'alguien@ejemplo.test', 'rol' => 'lector'])
        ->assertSessionHas('error', 'En la demo no se mandan mails.');

    Mail::assertNothingSent();
});

it('con el flag, mandar documentos y pedir medicamentos no mandan nada ni lo registran', function (string $ruta): void {
    encenderDemo();
    Mail::fake();
    $usuario = User::factory()->create();
    Contacto::factory()->create(['usuario_id' => $usuario->id]);

    $this->actingAs($usuario)
        ->post(route($ruta), [])
        ->assertSessionHas('error', 'En la demo no se mandan mails.');

    Mail::assertNothingSent();
    expect($usuario->envios()->count())->toBe(0);
})->with(['envios.store', 'pedidos.store']);

it('demo:reset se niega sin el flag y no borra nada', function (): void {
    $usuario = User::factory()->create();

    expect(Artisan::call('demo:reset'))->toBe(1)
        ->and($usuario->fresh())->not->toBeNull();
});
