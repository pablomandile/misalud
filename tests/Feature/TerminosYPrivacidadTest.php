<?php

use App\Http\Middleware\AceptoTerminos;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as UsuarioDeSocialite;

/*
 * Términos y privacidad: una versión vigente en `config('legales.version')`, la
 * constancia de quién la aceptó en `users`, y el middleware `AceptoTerminos`
 * que frena a quien no la aceptó, salvo para lo que tiene que poder hacer igual:
 * leer los textos, salir, llevarse sus datos y eliminar su cuenta.
 */

function datosDeRegistro(array $extra = []): array
{
    return [
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$extra,
    ];
}

it('no deja registrarse sin aceptar', function (): void {
    $this->post(route('register.store'), datosDeRegistro())
        ->assertSessionHasErrors('terminos');

    $this->assertGuest();
});

it('al registrarse con la casilla deja la constancia y no vuelve a preguntar', function (): void {
    // "on" es lo que manda de verdad una casilla nativa tildada.
    $this->post(route('register.store'), datosDeRegistro(['terminos' => 'on']));

    $usuario = User::where('email', 'ana@example.com')->firstOrFail();

    expect($usuario->terminos_version)->toBe(config('legales.version'))
        ->and($usuario->terminos_aceptados_en)->not->toBeNull();

    $usuario->markEmailAsVerified();
    $this->actingAs($usuario)->get(route('dashboard'))->assertOk();
});

it('a una cuenta sin aceptar la manda a aceptar, y después vuelve a donde iba', function (): void {
    $usuario = User::factory()->sinAceptarTerminos()->create();

    $this->actingAs($usuario)
        ->get(route('pacientes.index'))
        ->assertRedirect(route('terminos.aceptar'));

    $this->get(route('terminos.aceptar'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('terminos/Aceptar')
            ->where('primeraVez', true)
        );

    $this->post(route('terminos.aceptar.store'), ['terminos' => 'on'])
        ->assertRedirect(route('pacientes.index'));

    expect($usuario->fresh()->aceptoTerminosVigentes())->toBeTrue();
});

it('aceptar exige marcar la casilla', function (): void {
    $usuario = User::factory()->sinAceptarTerminos()->create();

    $this->actingAs($usuario)
        ->post(route('terminos.aceptar.store'))
        ->assertSessionHasErrors('terminos');

    expect($usuario->fresh()->terminos_version)->toBeNull();
});

it('subir la versión vuelve a pedir la aceptación', function (): void {
    $usuario = User::factory()->create();
    $this->actingAs($usuario)->get(route('dashboard'))->assertOk();

    config()->set('legales.version', '2099-01-01');

    $this->get(route('dashboard'))->assertRedirect(route('terminos.aceptar'));
    $this->get(route('terminos.aceptar'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('primeraVez', false));
});

it('sin aceptar igual puede leer, agrandar la letra, llevarse sus datos y eliminar la cuenta', function (): void {
    $usuario = User::factory()->sinAceptarTerminos()->create();
    $this->actingAs($usuario);

    foreach (['terminos', 'privacidad', 'cookies', 'profile.edit'] as $ruta) {
        $this->get(route($ruta))->assertOk();
    }

    $this->put(route('tamanio-texto.update'), ['tamanio_texto' => 'muy-grande'])
        ->assertRedirect();

    $this->get(route('exportacion.datos'))->assertOk();

    $this->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect('/');
    expect(User::find($usuario->id))->toBeNull();
});

it('un pedido JSON sin aceptar recibe 409 y no una redirección', function (): void {
    $usuario = User::factory()->sinAceptarTerminos()->create();

    $this->actingAs($usuario)
        ->getJson(route('dashboard'))
        ->assertStatus(409);
});

it('las exentas son solo las que se listan: el resto de las rutas con sesión quedan cubiertas', function (): void {
    // El middleware va en todo el grupo web, así que una ruta nueva nace
    // cubierta. Lo que se cuida acá es que la lista de exentas no crezca sin
    // querer: cada ruta con sesión exenta tiene que estar acá, a propósito.
    $exentasConSesion = collect(Route::getRoutes())
        ->filter(fn ($r) => collect($r->gatherMiddleware())->contains(fn ($m) => $m === 'auth' || str_starts_with((string) $m, 'auth:')))
        ->map(fn ($r) => $r->getName())
        ->filter(fn ($nombre) => $nombre !== null && Str::is(AceptoTerminos::EXENTAS, $nombre))
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($exentasConSesion)->toBe([
        'exportacion.datos',
        'logout',
        'profile.destroy',
        'profile.edit',
        'terminos.aceptar',
        'terminos.aceptar.store',
        'verification.notice',
        'verification.send',
        'verification.verify',
    ]);
});

it('las páginas de términos y privacidad se abren con sesión y sin ella', function (string $ruta): void {
    $this->get(route($ruta))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Legal')
            ->where('auth.user', null)
            ->has('html')
        );

    $this->actingAs(User::factory()->create())
        ->get(route($ruta))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Legal'));
})->with(['terminos', 'privacidad']);

it('una cuenta nueva con Google cae en la pantalla de aceptación', function (): void {
    config([
        'services.google.client_id' => 'id-de-prueba.apps.googleusercontent.com',
        'services.google.client_secret' => 'secreto-de-prueba',
        'services.google.redirect' => 'http://localhost/auth/google/callback',
    ]);

    $cuenta = new UsuarioDeSocialite;
    $cuenta->map(['id' => 'sub-nuevo', 'email' => 'nueva@example.com', 'name' => 'Nueva']);
    $cuenta->setRaw(['email_verified' => true]);
    Socialite::shouldReceive('driver')->with('google')->andReturn(
        Mockery::mock()->shouldReceive('user')->andReturn($cuenta)->getMock(),
    );

    $this->get(route('google.callback'));

    expect(User::firstWhere('email', 'nueva@example.com')?->terminos_version)->toBeNull();
    $this->get(route('dashboard'))->assertRedirect(route('terminos.aceptar'));
});

it('Perfil ofrece descargar los datos, que es lo que promete la política', function (): void {
    $perfil = (string) file_get_contents(resource_path('js/pages/settings/Profile.vue'));

    expect(str_contains($perfil, 'misDatos().url'))->toBeTrue()
        ->and(str_contains((string) file_get_contents(resource_path('legales/privacidad.md')), 'Descargar mis datos'))->toBeTrue();
});

it('la descarga trae los datos descifrados de las fichas propias, y no los de las compartidas', function (): void {
    $usuario = User::factory()->create();
    Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Rosa Propia']);
    $ajena = Paciente::factory()->create(['nombre' => 'Otra Persona']);
    $ajena->cuidadores()->attach($usuario->id, ['rol' => 'lector']);

    $respuesta = $this->actingAs($usuario)->get(route('exportacion.datos'));
    $respuesta->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $zip = new ZipArchive;
    $zip->open($respuesta->baseResponse->getFile()->getPathname());
    $json = (string) $zip->getFromName('datos.json');
    $zip->close();

    expect($json)->toContain('Rosa Propia')
        ->not->toContain('Otra Persona')
        ->not->toContain('_hash')
        ->and($zip->numFiles ?? 0)->toBeGreaterThanOrEqual(0);
});

it('el service worker no guarda una credencial que llegó redirigida', function (): void {
    // Sin aceptar los términos, /credenciales/{id} redirige a "aceptar y
    // continuar", y `fetch` sigue el 302 hasta un 200: sin esto se guardaría esa
    // pantalla como si fuera la foto de la credencial.
    $sw = (string) file_get_contents(public_path('sw.js'));

    expect($sw)->toContain('respuesta.ok && !respuesta.redirected');
});
