<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as UsuarioDeSocialite;

/**
 * Deja las credenciales cargadas, que es lo que enciende toda la función.
 */
function conCredencialesDeGoogle(): void
{
    config([
        'services.google.client_id' => 'id-de-prueba.apps.googleusercontent.com',
        'services.google.client_secret' => 'secreto-de-prueba',
        'services.google.redirect' => 'http://localhost/auth/google/callback',
    ]);
}

/**
 * Arma lo que devolvería Socialite, con el flag de verificado en el payload
 * crudo, que es donde Google lo manda de verdad.
 */
function respuestaDeGoogle(
    string $id,
    string $email,
    ?string $nombre = 'Ana Pérez',
    bool $emailVerificado = true,
): void {
    $cuenta = new UsuarioDeSocialite;
    $cuenta->map(['id' => $id, 'email' => $email, 'name' => $nombre]);
    $cuenta->setRaw(['email_verified' => $emailVerificado]);

    Socialite::shouldReceive('driver')->with('google')->andReturn(
        Mockery::mock()->shouldReceive('user')->andReturn($cuenta)->getMock(),
    );
}

/*
|--------------------------------------------------------------------------
| Sin credenciales, la opción no existe
|--------------------------------------------------------------------------
*/

it('da 404 en las rutas de Google si no hay credenciales', function (): void {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

    $this->get(route('google.redirect'))->assertNotFound();
    $this->get(route('google.callback'))->assertNotFound();
});

it('no ofrece el botón de Google si no hay credenciales', function (): void {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('googleHabilitado', false));
});

it('ofrece el botón de Google cuando hay credenciales', function (): void {
    conCredencialesDeGoogle();

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('googleHabilitado', true));
});

it('manda a Google al pedir el ingreso', function (): void {
    conCredencialesDeGoogle();

    $this->get(route('google.redirect'))
        ->assertRedirectContains('accounts.google.com');
});

/*
|--------------------------------------------------------------------------
| Alta y vínculo de cuentas
|--------------------------------------------------------------------------
*/

it('crea la cuenta, sin contraseña y con el email ya verificado', function (): void {
    conCredencialesDeGoogle();
    respuestaDeGoogle('sub-123', 'ana@example.com', 'Ana Pérez');

    $this->get(route('google.callback'))->assertRedirect(route('dashboard'));

    $usuario = User::firstWhere('email', 'ana@example.com');

    expect($usuario)->not->toBeNull()
        ->and($usuario->name)->toBe('Ana Pérez')
        ->and($usuario->google_id)->toBe('sub-123')
        ->and($usuario->password)->toBeNull()
        ->and($usuario->hasVerifiedEmail())->toBeTrue();

    $this->assertAuthenticatedAs($usuario);
});

it('usa la parte del email como nombre si Google no manda ninguno', function (): void {
    conCredencialesDeGoogle();
    respuestaDeGoogle('sub-123', 'ana.perez@example.com', nombre: null);

    $this->get(route('google.callback'));

    expect(User::firstWhere('email', 'ana.perez@example.com')->name)->toBe('ana.perez');
});

it('vincula la cuenta existente en vez de crear una segunda con el mismo email', function (): void {
    conCredencialesDeGoogle();
    $existente = User::factory()->create(['email' => 'ana@example.com']);
    respuestaDeGoogle('sub-123', 'ana@example.com');

    $this->get(route('google.callback'))->assertRedirect(route('dashboard'));

    expect(User::count())->toBe(1)
        ->and($existente->fresh()->google_id)->toBe('sub-123');

    $this->assertAuthenticatedAs($existente);
});

it('le conserva la contraseña a quien ya la tenía al vincular con Google', function (): void {
    conCredencialesDeGoogle();
    $existente = User::factory()->create([
        'email' => 'ana@example.com',
        'password' => 'clave-de-antes',
    ]);
    respuestaDeGoogle('sub-123', 'ana@example.com');

    $this->get(route('google.callback'));

    expect(Hash::check('clave-de-antes', $existente->fresh()->password))->toBeTrue();
});

it('da por verificado el email de quien se había registrado y no lo había confirmado', function (): void {
    conCredencialesDeGoogle();
    $existente = User::factory()->unverified()->create(['email' => 'ana@example.com']);
    respuestaDeGoogle('sub-123', 'ana@example.com');

    $this->get(route('google.callback'));

    expect($existente->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('reconoce por el sub de Google y actualiza el email si cambió', function (): void {
    conCredencialesDeGoogle();
    $existente = User::factory()->create(['email' => 'viejo@example.com']);
    $existente->forceFill(['google_id' => 'sub-123'])->save();

    respuestaDeGoogle('sub-123', 'nuevo@example.com');

    $this->get(route('google.callback'))->assertRedirect(route('dashboard'));

    expect(User::count())->toBe(1)
        ->and($existente->fresh()->email)->toBe('nuevo@example.com');
});

/*
|--------------------------------------------------------------------------
| Lo que se rechaza
|--------------------------------------------------------------------------
*/

it('rechaza la cuenta si Google no verificó el email', function (): void {
    conCredencialesDeGoogle();
    respuestaDeGoogle('sub-123', 'ana@example.com', emailVerificado: false);

    $this->get(route('google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    expect(User::count())->toBe(0);
    $this->assertGuest();
});

it('rechaza la cuenta si Google no devolvió email', function (): void {
    conCredencialesDeGoogle();
    respuestaDeGoogle('sub-123', '');

    $this->get(route('google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    expect(User::count())->toBe(0);
});

it('vuelve al login sin cartel de error si la persona canceló en Google', function (): void {
    conCredencialesDeGoogle();

    $this->get(route('google.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('login'))
        ->assertSessionMissing('error');

    $this->assertGuest();
});

it('no muestra el detalle del error de Socialite', function (): void {
    conCredencialesDeGoogle();

    Socialite::shouldReceive('driver')->with('google')->andReturn(
        Mockery::mock()->shouldReceive('user')
            ->andThrow(new RuntimeException('client_secret invalido: GOCSPX-secreto'))
            ->getMock(),
    );

    $respuesta = $this->get(route('google.callback'))->assertRedirect(route('login'));

    expect($respuesta->getSession()->get('error'))->not->toContain('GOCSPX');
});

/*
|--------------------------------------------------------------------------
| La cuenta sin contraseña
|--------------------------------------------------------------------------
*/

it('NO deja entrar con email y contraseña a una cuenta que no tiene ninguna', function (string $intento): void {
    $usuario = User::factory()->create(['email' => 'ana@example.com']);
    $usuario->forceFill(['password' => null])->save();

    $this->post(route('login.store'), [
        'email' => 'ana@example.com',
        'password' => $intento,
    ]);

    $this->assertGuest();
})->with([
    'contraseña vacía' => '',
    'cualquier contraseña' => 'la-que-sea',
]);

it('deja ver la pantalla de seguridad sin confirmar una contraseña que no existe', function (): void {
    $usuario = User::factory()->create();
    $usuario->forceFill(['password' => null, 'google_id' => 'sub-123'])->save();

    $this->actingAs($usuario)
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('tieneContrasena', false));
});

it('le sigue pidiendo confirmar la contraseña a quien sí la tiene', function (): void {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->get(route('security.edit'))
        ->assertRedirect(route('password.confirm'));
});

it('deja definir una primera contraseña sin pedir la anterior', function (): void {
    $usuario = User::factory()->create();
    $usuario->forceFill(['password' => null, 'google_id' => 'sub-123'])->save();

    $this->actingAs($usuario)
        ->put(route('user-password.update'), [
            'password' => 'Clave-Nueva-2026',
            'password_confirmation' => 'Clave-Nueva-2026',
        ])
        ->assertSessionHasNoErrors();

    expect(Hash::check('Clave-Nueva-2026', $usuario->fresh()->password))->toBeTrue();
});

it('le sigue exigiendo la contraseña actual a quien ya tiene una', function (): void {
    $usuario = User::factory()->create(['password' => 'clave-de-antes']);

    $this->actingAs($usuario)
        ->put(route('user-password.update'), [
            'password' => 'Clave-Nueva-2026',
            'password_confirmation' => 'Clave-Nueva-2026',
        ])
        ->assertSessionHasErrors('current_password');
});

it('deja eliminar la cuenta a quien no tiene contraseña', function (): void {
    $usuario = User::factory()->create();
    $usuario->forceFill(['password' => null, 'google_id' => 'sub-123'])->save();

    $this->actingAs($usuario)
        ->delete(route('profile.destroy'))
        ->assertRedirect('/');

    expect(User::find($usuario->id))->toBeNull();
});

it('le sigue pidiendo la contraseña para eliminar la cuenta a quien la tiene', function (): void {
    $usuario = User::factory()->create(['password' => 'clave-de-antes']);

    $this->actingAs($usuario)
        ->delete(route('profile.destroy'), ['password' => 'equivocada'])
        ->assertSessionHasErrors('password');

    expect(User::find($usuario->id))->not->toBeNull();
});
