<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Mail\InvitacionAFicha;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    Mail::fake();

    $this->duenio = User::factory()->create(['name' => 'Ana', 'email' => 'ana@misalud.test']);
    $this->paciente = Paciente::factory()->for($this->duenio, 'usuario')->create(['nombre' => 'Rosa Gómez']);
});

/** La URL firmada como la arma `CompartirController::invitar()`. */
function enlaceDeInvitacion(Paciente $paciente, string $email, string $rol, ?DateTimeInterface $vence = null): string
{
    return URL::temporarySignedRoute(
        'invitaciones.mostrar',
        $vence ?? now()->addDays(7),
        ['paciente' => $paciente->id, 'email' => $email, 'rol' => $rol],
    );
}

function sumarALaFicha(Paciente $paciente, User $usuario, RolPaciente $rol): void
{
    $paciente->cuidadores()->attach($usuario, ['rol' => $rol->value]);
}

/*
|--------------------------------------------------------------------------
| Invitar
|--------------------------------------------------------------------------
*/

it('el propietario invita por mail, y el asunto NO lleva el nombre del paciente', function (): void {
    $this->actingAs($this->duenio)
        ->post(route('pacientes.invitaciones.store', $this->paciente), [
            'email' => ' Hermano@Familia.TEST ',
            'rol' => 'cuidador',
        ])
        ->assertSessionHas('exito', 'Le mandamos la invitación a hermano@familia.test.');

    Mail::assertSent(InvitacionAFicha::class, function (InvitacionAFicha $mail): bool {
        $mail->assertHasTo('hermano@familia.test');
        // El asunto es lo que se ve en la pantalla bloqueada.
        expect($mail->envelope()->subject)->not->toContain('Rosa');

        $cuerpo = $mail->render();
        expect($cuerpo)->toContain('Rosa Gómez')
            ->and($cuerpo)->toContain('cargar y corregir datos')
            ->and($cuerpo)->toContain('/invitaciones/')
            ->and($cuerpo)->toContain('signature=');

        return true;
    });
});

it('⚠️ el enlace del mail, tal como sale ESCRITO, abre la invitación', function (): void {
    /*
     * Con `{{ $enlace }}` en la vista, Blade escapaba el "&" a "&amp;": la firma no
     * coincidía y TODA invitación llegaba inservible. El test de arriba no lo veía,
     * porque solo buscaba "signature=" en el cuerpo. Este sigue el enlace de verdad.
     */
    $this->actingAs($this->duenio)
        ->post(route('pacientes.invitaciones.store', $this->paciente), ['email' => 'nuevo@familia.test', 'rol' => 'lector']);

    $enlace = null;
    Mail::assertSent(InvitacionAFicha::class, function (InvitacionAFicha $mail) use (&$enlace): bool {
        preg_match('#https?://\S+/invitaciones/\S+#', $mail->render(), $m);
        $enlace = $m[0] ?? null;

        return true;
    });

    expect($enlace)->not->toBeNull()->not->toContain('&amp;');

    auth()->logout();

    $this->get($enlace)
        ->assertInertia(fn ($pagina) => $pagina->where('estado', 'sin_sesion'));
});

it('⚠️ solo el propietario invita: un cuidador no puede', function (): void {
    $cuidador = User::factory()->create();
    sumarALaFicha($this->paciente, $cuidador, RolPaciente::Cuidador);

    $this->actingAs($cuidador)
        ->post(route('pacientes.invitaciones.store', $this->paciente), ['email' => 'x@y.test', 'rol' => 'lector'])
        ->assertForbidden();

    Mail::assertNothingSent();
});

it('⚠️ nunca se invita como propietario: la lista blanca tiene dos casos', function (): void {
    $this->actingAs($this->duenio)
        ->post(route('pacientes.invitaciones.store', $this->paciente), ['email' => 'x@y.test', 'rol' => 'propietario'])
        ->assertSessionHasErrors('rol');

    Mail::assertNothingSent();
});

it('no invita a quien ya tiene acceso', function (): void {
    $lector = User::factory()->create(['email' => 'lector@familia.test']);
    sumarALaFicha($this->paciente, $lector, RolPaciente::Lector);

    $this->actingAs($this->duenio)
        ->post(route('pacientes.invitaciones.store', $this->paciente), ['email' => 'LECTOR@familia.test', 'rol' => 'cuidador'])
        ->assertSessionHasErrors('email');
});

/*
|--------------------------------------------------------------------------
| Abrir la invitación
|--------------------------------------------------------------------------
*/

it('sin sesión muestra el nombre y quién invita, nada clínico, y recuerda a dónde volver', function (): void {
    $enlace = enlaceDeInvitacion($this->paciente, 'nuevo@familia.test', 'lector');

    $this->get($enlace)
        ->assertInertia(fn ($pagina) => $pagina
            ->component('invitaciones/Aceptar')
            ->where('estado', 'sin_sesion')
            ->where('paciente', 'Rosa Gómez')
            ->where('invitadoPor', 'Ana')
            ->where('email', 'nuevo@familia.test')
            ->where('puedeEditar', false));

    expect(session('url.intended'))->toBe($enlace);
});

it('⚠️ un enlace tocado no muestra NADA del paciente', function (): void {
    // Cambiar el rol en la URL invalida la firma entera.
    $tocado = str_replace('rol=lector', 'rol=cuidador', enlaceDeInvitacion($this->paciente, 'x@y.test', 'lector'));

    $this->get($tocado)
        ->assertInertia(fn ($pagina) => $pagina
            ->where('estado', 'invalida')
            ->where('paciente', null)
            ->where('invitadoPor', null));
});

it('un enlace vencido dice de quién es y a quién pedirle otro', function (): void {
    $vencido = enlaceDeInvitacion($this->paciente, 'x@y.test', 'lector', now()->subMinute());

    $this->get($vencido)
        ->assertInertia(fn ($pagina) => $pagina
            ->where('estado', 'vencida')
            ->where('paciente', 'Rosa Gómez')
            ->where('invitadoPor', 'Ana'));
});

/*
|--------------------------------------------------------------------------
| Aceptar
|--------------------------------------------------------------------------
*/

it('acepta con la cuenta correcta y queda con el rol de la invitación', function (): void {
    $invitado = User::factory()->create(['email' => 'nuevo@familia.test']);
    $enlace = enlaceDeInvitacion($this->paciente, 'nuevo@familia.test', 'cuidador');

    $this->actingAs($invitado)
        ->post($enlace)
        ->assertRedirect(route('pacientes.index'))
        ->assertSessionHas('exito', 'Ya podés ver la ficha de Rosa Gómez.');

    expect($this->paciente->fresh()->rolDe($invitado))->toBe(RolPaciente::Cuidador);
});

it('⚠️ el propietario que abre su propia invitación NO queda degradado', function (): void {
    /*
     * Con `syncWithoutDetaching` en vez de `attach`, aceptar ACTUALIZARÍA el rol de
     * la fila existente: la dueña quedaría de lectora de su propia ficha.
     */
    $enlace = enlaceDeInvitacion($this->paciente, 'ana@misalud.test', 'lector');

    $this->actingAs($this->duenio)->get($enlace)
        ->assertInertia(fn ($pagina) => $pagina->where('estado', 'ya_tiene_acceso'));

    $this->actingAs($this->duenio)->post($enlace)->assertRedirect(route('pacientes.index'));

    expect($this->paciente->fresh()->rolDe($this->duenio))->toBe(RolPaciente::Propietario);
});

it('⚠️ reenviar el mail a otra persona no le sirve', function (): void {
    $otro = User::factory()->create(['email' => 'intruso@otro.test']);
    $enlace = enlaceDeInvitacion($this->paciente, 'nuevo@familia.test', 'cuidador');

    $this->actingAs($otro)->get($enlace)
        ->assertInertia(fn ($pagina) => $pagina->where('estado', 'otra_cuenta'));

    $this->actingAs($otro)->post($enlace)->assertForbidden();

    expect($this->paciente->fresh()->rolDe($otro))->toBeNull();
});

it('⚠️ con el mail sin verificar no se acepta', function (): void {
    // Sin esto, cualquiera podría registrarse declarando el mail de otro.
    $invitado = User::factory()->unverified()->create(['email' => 'nuevo@familia.test']);
    $enlace = enlaceDeInvitacion($this->paciente, 'nuevo@familia.test', 'cuidador');

    $this->actingAs($invitado)->post($enlace)->assertForbidden();

    expect($this->paciente->fresh()->rolDe($invitado))->toBeNull();
});

it('⚠️ aceptar exige la firma: tocada o vencida, 403', function (): void {
    $invitado = User::factory()->create(['email' => 'nuevo@familia.test']);

    $tocado = str_replace('rol=lector', 'rol=cuidador', enlaceDeInvitacion($this->paciente, 'nuevo@familia.test', 'lector'));
    $this->actingAs($invitado)->post($tocado)->assertForbidden();

    $vencido = enlaceDeInvitacion($this->paciente, 'nuevo@familia.test', 'lector', now()->subMinute());
    $this->actingAs($invitado)->post($vencido)->assertForbidden();

    expect($this->paciente->fresh()->rolDe($invitado))->toBeNull();
});

it('⚠️ aunque se filtrara la clave de firma, nunca se concede propietario', function (): void {
    /*
     * Una URL firmada "de verdad" con rol=propietario es lo que podría armar quien
     * tenga la APP_KEY. La lista blanca de `rolInvitado()` la baja a lector: una
     * autorización no puede depender de un solo candado.
     */
    $invitado = User::factory()->create(['email' => 'nuevo@familia.test']);
    $enlace = enlaceDeInvitacion($this->paciente, 'nuevo@familia.test', 'propietario');

    $this->actingAs($invitado)->post($enlace)->assertRedirect();

    expect($this->paciente->fresh()->rolDe($invitado))->toBe(RolPaciente::Lector);
});

/*
|--------------------------------------------------------------------------
| Cambiar el permiso y sacar acceso
|--------------------------------------------------------------------------
*/

it('el propietario pasa a alguien de lector a cuidador y al revés', function (): void {
    $otro = User::factory()->create();
    sumarALaFicha($this->paciente, $otro, RolPaciente::Lector);

    $this->actingAs($this->duenio)
        ->patch(route('pacientes.accesos.update', [$this->paciente, $otro]), ['rol' => 'cuidador'])
        ->assertSessionHas('exito');

    expect($this->paciente->fresh()->rolDe($otro))->toBe(RolPaciente::Cuidador);
});

it('⚠️ al propietario no se le cambia el rol', function (): void {
    $this->actingAs($this->duenio)
        ->patch(route('pacientes.accesos.update', [$this->paciente, $this->duenio]), ['rol' => 'lector'])
        ->assertForbidden();

    expect($this->paciente->fresh()->rolDe($this->duenio))->toBe(RolPaciente::Propietario);
});

it('⚠️ cambiar un permiso tampoco puede dar propietario', function (): void {
    $otro = User::factory()->create();
    sumarALaFicha($this->paciente, $otro, RolPaciente::Cuidador);

    $this->actingAs($this->duenio)
        ->patch(route('pacientes.accesos.update', [$this->paciente, $otro]), ['rol' => 'propietario'])
        ->assertSessionHasErrors('rol');

    expect($this->paciente->fresh()->rolDe($otro))->toBe(RolPaciente::Cuidador);
});

it('un cuidador no le cambia el permiso a nadie', function (): void {
    $cuidador = User::factory()->create();
    $lector = User::factory()->create();
    sumarALaFicha($this->paciente, $cuidador, RolPaciente::Cuidador);
    sumarALaFicha($this->paciente, $lector, RolPaciente::Lector);

    $this->actingAs($cuidador)
        ->patch(route('pacientes.accesos.update', [$this->paciente, $lector]), ['rol' => 'cuidador'])
        ->assertForbidden();
});

it('el propietario le saca el acceso a alguien', function (): void {
    $otro = User::factory()->create();
    sumarALaFicha($this->paciente, $otro, RolPaciente::Cuidador);

    $this->actingAs($this->duenio)
        ->delete(route('pacientes.accesos.destroy', [$this->paciente, $otro]))
        ->assertSessionHas('exito');

    expect($this->paciente->fresh()->rolDe($otro))->toBeNull();
});

it('cualquiera se puede ir solo, y vuelve al listado', function (): void {
    $lector = User::factory()->create();
    sumarALaFicha($this->paciente, $lector, RolPaciente::Lector);

    $this->actingAs($lector)
        ->delete(route('pacientes.accesos.destroy', [$this->paciente, $lector]))
        ->assertRedirect(route('pacientes.index'));

    expect($this->paciente->fresh()->rolDe($lector))->toBeNull();
});

it('⚠️ el propietario no se puede sacar a sí mismo', function (): void {
    // Se quedaría sin su ficha, y con ella sin nadie que pueda compartirla.
    $this->actingAs($this->duenio)
        ->delete(route('pacientes.accesos.destroy', [$this->paciente, $this->duenio]))
        ->assertForbidden();

    expect($this->paciente->fresh()->rolDe($this->duenio))->toBe(RolPaciente::Propietario);
});

it('un cuidador no saca a otros, solo se puede ir él', function (): void {
    $cuidador = User::factory()->create();
    $lector = User::factory()->create();
    sumarALaFicha($this->paciente, $cuidador, RolPaciente::Cuidador);
    sumarALaFicha($this->paciente, $lector, RolPaciente::Lector);

    $this->actingAs($cuidador)
        ->delete(route('pacientes.accesos.destroy', [$this->paciente, $lector]))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| La pantalla
|--------------------------------------------------------------------------
*/

it('⚠️ las direcciones de los demás las ve solo el propietario', function (): void {
    $lector = User::factory()->create(['email' => 'lector@familia.test']);
    $cuidador = User::factory()->create(['email' => 'cuidador@familia.test']);
    sumarALaFicha($this->paciente, $lector, RolPaciente::Lector);
    sumarALaFicha($this->paciente, $cuidador, RolPaciente::Cuidador);

    // La dueña ve todas, con el propietario primero.
    $this->actingAs($this->duenio)
        ->get(route('pacientes.index'))
        ->assertInertia(fn ($pagina) => $pagina
            ->has('pacientes.0.accesos', 3)
            ->where('pacientes.0.accesos.0.rol', 'propietario')
            ->where('pacientes.0.accesos.1.email', 'cuidador@familia.test')
            ->where('pacientes.0.accesos.2.email', 'lector@familia.test'));

    // El lector ve los nombres y su propio mail, no el de los demás.
    $respuesta = $this->actingAs($lector)->get(route('pacientes.index'));

    $respuesta->assertInertia(fn ($pagina) => $pagina
        ->where('pacientes.0.accesos.0.email', null)
        ->where('pacientes.0.accesos.1.email', null)
        ->where('pacientes.0.accesos.2.email', 'lector@familia.test'));

    expect($respuesta->getContent())->not->toContain('cuidador@familia.test')
        ->and($respuesta->getContent())->not->toContain('ana@misalud.test');
});
