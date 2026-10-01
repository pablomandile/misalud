<?php

declare(strict_types=1);

use App\Enums\EstadoDeConexion;
use App\Models\CuentaMail;
use App\Models\User;
use App\Services\ProbadorDeCasilla;
use App\Support\PruebaDeConexion;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Los datos válidos de una casilla.
 *
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function datosDeCasilla(array $cambios = []): array
{
    return array_merge([
        'direccion' => 'recetas@ejemplo.com',
        'password' => 'clave-de-aplicacion',
        'host' => 'imap.ejemplo.test',
        'puerto' => 993,
        'carpeta' => 'INBOX',
    ], $cambios);
}

beforeEach(function (): void {
    $this->usuario = User::factory()->create();
});

/*
|--------------------------------------------------------------------------
| Alta, edición y baja
|--------------------------------------------------------------------------
*/

it('guarda una casilla y la deja colgada del usuario', function (): void {
    $this->actingAs($this->usuario)
        ->post(route('casilla.store'), datosDeCasilla())
        ->assertRedirect();

    $cuenta = CuentaMail::sole();

    expect($cuenta->usuario_id)->toBe($this->usuario->id)
        ->and($cuenta->direccion)->toBe('recetas@ejemplo.com')
        ->and($cuenta->password)->toBe('clave-de-aplicacion')
        ->and($cuenta->carpeta)->toBe('INBOX');
});

it('lista solo las casillas propias', function (): void {
    CuentaMail::factory()->for($this->usuario, 'usuario')->create();
    CuentaMail::factory()->create();   // de otro usuario

    $this->actingAs($this->usuario)
        ->get(route('casilla.index'))
        ->assertInertia(fn ($pagina) => $pagina
            ->component('casilla/Index')
            ->has('cuentas', 1));
});

it('edita la casilla sin tocar la contraseña si el campo viene vacío', function (): void {
    /*
     * El caso central de "la contraseña no viaja al navegador": el formulario
     * de edición abre con ese campo vacío, así que vacío tiene que significar
     * "dejá la que está". Si lo borrara, editar la carpeta dejaría la casilla
     * sin credenciales y la sincronización empezaría a fallar por un campo que
     * nadie tocó.
     */
    $cuenta = CuentaMail::factory()->for($this->usuario, 'usuario')->create([
        'password' => 'la-que-ya-tenia',
    ]);

    $this->actingAs($this->usuario)
        ->put(route('casilla.update', $cuenta), datosDeCasilla([
            'password' => '',
            'carpeta' => 'INBOX.Recetas',
        ]))
        ->assertRedirect();

    expect($cuenta->fresh()->password)->toBe('la-que-ya-tenia')
        ->and($cuenta->fresh()->carpeta)->toBe('INBOX.Recetas');
});

it('cambia la contraseña cuando sí viene una', function (): void {
    $cuenta = CuentaMail::factory()->for($this->usuario, 'usuario')->create([
        'password' => 'la-vieja',
    ]);

    $this->actingAs($this->usuario)
        ->put(route('casilla.update', $cuenta), datosDeCasilla(['password' => 'la-nueva']))
        ->assertRedirect();

    expect($cuenta->fresh()->password)->toBe('la-nueva');
});

it('borra de verdad, sin soft delete: la contraseña no puede quedar guardada', function (): void {
    $cuenta = CuentaMail::factory()->for($this->usuario, 'usuario')->create();

    $this->actingAs($this->usuario)
        ->delete(route('casilla.destroy', $cuenta))
        ->assertRedirect();

    // Contra la tabla cruda: un soft delete dejaría la fila -y la
    // contraseña- ahí, y `CuentaMail::count()` no lo notaría.
    expect(DB::table('cuentas_mail')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| La contraseña no sale del servidor
|--------------------------------------------------------------------------
*/

it('⚠️ NO manda la contraseña a la pantalla', function (): void {
    CuentaMail::factory()->for($this->usuario, 'usuario')->create([
        'password' => 'clave-secretisima-de-aplicacion',
    ]);

    $respuesta = $this->actingAs($this->usuario)->get(route('casilla.index'));

    $respuesta->assertInertia(fn ($pagina) => $pagina->has('cuentas.0', fn ($c) => $c
        ->hasAll(['id', 'host', 'puerto', 'direccion', 'carpeta', 'filtros'])
        // Explícito además del `hasAll`: si alguien suma la clave al
        // serializador, este test dice exactamente qué se rompió.
        ->missing('password')));

    // Y tampoco por otro camino: ni en el HTML ni en el JSON de la página.
    expect($respuesta->getContent())->not->toContain('clave-secretisima-de-aplicacion');
});

it('guarda la contraseña cifrada en la base', function (): void {
    CuentaMail::factory()->for($this->usuario, 'usuario')->create([
        'password' => 'clave-de-aplicacion',
        'direccion' => 'recetas@ejemplo.com',
    ]);

    $fila = DB::table('cuentas_mail')->sole();

    expect($fila->password)->not->toBe('clave-de-aplicacion')
        ->and($fila->direccion)->not->toBe('recetas@ejemplo.com')
        ->and(Crypt::decryptString($fila->password))->toBe('clave-de-aplicacion')
        // El host NO se cifra: es con lo que hay que conectarse y no dice
        // nada de nadie.
        ->and($fila->host)->toBe('imap.ejemplo.test');
});

/*
|--------------------------------------------------------------------------
| Autorización
|--------------------------------------------------------------------------
*/

it('no deja ver, editar, borrar ni probar la casilla de otro', function (): void {
    $ajena = CuentaMail::factory()->create();

    $this->actingAs($this->usuario)
        ->put(route('casilla.update', $ajena), datosDeCasilla())
        ->assertForbidden();

    $this->actingAs($this->usuario)
        ->delete(route('casilla.destroy', $ajena))
        ->assertForbidden();

    $this->actingAs($this->usuario)
        ->post(route('casilla.probar', $ajena))
        ->assertForbidden();
});

it('pide sesión', function (): void {
    $this->get(route('casilla.index'))->assertRedirect(route('login'));
});

/*
|--------------------------------------------------------------------------
| Validación
|--------------------------------------------------------------------------
*/

it('rechaza un servidor con el puerto pegado adentro', function (): void {
    /*
     * El error de pegado más común. Sin esta regla se guarda igual y lo que
     * falla después es la conexión, con un "no se llega al servidor" que manda
     * a revisar la red en vez del campo.
     */
    $this->actingAs($this->usuario)
        ->post(route('casilla.store'), datosDeCasilla(['host' => 'imap.gmail.com:993']))
        ->assertSessionHasErrors('host');

    $this->actingAs($this->usuario)
        ->post(route('casilla.store'), datosDeCasilla(['host' => 'imap://imap.gmail.com']))
        ->assertSessionHasErrors('host');
});

it('acepta solo los dos puertos de los que se deduce la encriptación', function (): void {
    $this->actingAs($this->usuario)
        ->post(route('casilla.store'), datosDeCasilla(['puerto' => 1234]))
        ->assertSessionHasErrors('puerto');
});

it('exige la contraseña al crear, pero no al editar', function (): void {
    $this->actingAs($this->usuario)
        ->post(route('casilla.store'), datosDeCasilla(['password' => '']))
        ->assertSessionHasErrors('password');

    $cuenta = CuentaMail::factory()->for($this->usuario, 'usuario')->create();

    $this->actingAs($this->usuario)
        ->put(route('casilla.update', $cuenta), datosDeCasilla(['password' => '']))
        ->assertSessionHasNoErrors();
});

it('no deja configurar dos veces la misma dirección', function (): void {
    CuentaMail::factory()->for($this->usuario, 'usuario')->create([
        'direccion' => 'recetas@ejemplo.com',
    ]);

    $this->actingAs($this->usuario)
        ->post(route('casilla.store'), datosDeCasilla(['direccion' => 'recetas@ejemplo.com']))
        ->assertSessionHasErrors('direccion');
});

it('la misma dirección sí puede estar en dos usuarios distintos', function (): void {
    // La unicidad es "para esta persona", no global: dos personas pueden
    // administrar la misma casilla compartida de una familia.
    CuentaMail::factory()->create(['direccion' => 'recetas@ejemplo.com']);

    $this->actingAs($this->usuario)
        ->post(route('casilla.store'), datosDeCasilla(['direccion' => 'recetas@ejemplo.com']))
        ->assertSessionHasNoErrors();
});

it('editar la casilla sin cambiar la dirección no choca consigo misma', function (): void {
    $cuenta = CuentaMail::factory()->for($this->usuario, 'usuario')->create([
        'direccion' => 'recetas@ejemplo.com',
    ]);

    $this->actingAs($this->usuario)
        ->put(route('casilla.update', $cuenta), datosDeCasilla([
            'direccion' => 'recetas@ejemplo.com',
            'carpeta' => 'OTRA',
        ]))
        ->assertSessionHasNoErrors();
});

/*
|--------------------------------------------------------------------------
| Los filtros, que llegan de un textarea
|--------------------------------------------------------------------------
*/

it('parte los filtros por líneas y por comas, y normaliza', function (): void {
    $this->actingAs($this->usuario)
        ->post(route('casilla.store'), datosDeCasilla([
            'filtros' => "Recetas@Farmacia.com.ar\n  osde.com.ar  ,, obra@social.org\n\n",
        ]))
        ->assertSessionHasNoErrors();

    expect(CuentaMail::sole()->remitentesAceptados())->toBe([
        'recetas@farmacia.com.ar',
        'osde.com.ar',
        'obra@social.org',
    ]);
});

it('sin filtros significa "todo lo que haya en la carpeta"', function (): void {
    $this->actingAs($this->usuario)
        ->post(route('casilla.store'), datosDeCasilla(['filtros' => '']))
        ->assertSessionHasNoErrors();

    expect(CuentaMail::sole()->remitentesAceptados())->toBe([]);
});

it('rechaza un filtro que no es ni dirección ni dominio', function (): void {
    $this->actingAs($this->usuario)
        ->post(route('casilla.store'), datosDeCasilla(['filtros' => "farmacia\nsin-punto"]))
        ->assertSessionHasErrors('filtros.0');
});

it('guarda los filtros cifrados', function (): void {
    CuentaMail::factory()->for($this->usuario, 'usuario')
        ->filtrando(['recetas@farmacia.com.ar'])
        ->create();

    expect(DB::table('cuentas_mail')->sole()->filtros)
        ->not->toContain('farmacia.com.ar');
});

/*
|--------------------------------------------------------------------------
| La prueba de conexión
|--------------------------------------------------------------------------
*/

it('avisa en un toast de éxito cuando la casilla anduvo', function (): void {
    $cuenta = CuentaMail::factory()->for($this->usuario, 'usuario')->create();

    $this->mock(ProbadorDeCasilla::class)
        ->shouldReceive('probar')
        ->once()
        ->andReturn(PruebaDeConexion::ok());

    $this->actingAs($this->usuario)
        ->post(route('casilla.probar', $cuenta))
        ->assertSessionHas('exito');
});

it('⚠️ el aviso de error nombra el problema concreto, no "no se pudo conectar"', function (): void {
    /*
     * Es lo que justifica que `EstadoDeConexion` sea un enum y no un booleano:
     * una carpeta mal escrita deja la sincronización encontrando cero mensajes
     * para siempre, y un mensaje genérico manda a revisar la contraseña -lo
     * único que estaba bien-.
     */
    $cuenta = CuentaMail::factory()->for($this->usuario, 'usuario')->create();

    $this->mock(ProbadorDeCasilla::class)
        ->shouldReceive('probar')
        ->andReturn(PruebaDeConexion::fallo(
            EstadoDeConexion::CarpetaInexistente,
            'Las que hay son: INBOX, INBOX.Recetas.',
        ));

    $respuesta = $this->actingAs($this->usuario)->post(route('casilla.probar', $cuenta));

    $respuesta->assertSessionHas('error', fn (string $mensaje): bool => str_contains($mensaje, 'carpeta no existe')
        && str_contains($mensaje, 'INBOX.Recetas'));
});

it('no guarda el resultado de la prueba en ninguna parte', function (): void {
    /*
     * A propósito: una prueba vale en el instante en que se hizo. Un "última
     * prueba: anduvo" guardado envejece solo -la contraseña de aplicación se
     * revoca- y la pantalla lo mostraría en verde justo cuando dejó de ser
     * cierto.
     */
    $cuenta = CuentaMail::factory()->for($this->usuario, 'usuario')->create();
    $antes = DB::table('cuentas_mail')->sole();

    $this->mock(ProbadorDeCasilla::class)
        ->shouldReceive('probar')
        ->andReturn(PruebaDeConexion::fallo(EstadoDeConexion::SinRed));

    $this->actingAs($this->usuario)->post(route('casilla.probar', $cuenta));

    expect(DB::table('cuentas_mail')->sole())->toEqual($antes);
});

/*
|--------------------------------------------------------------------------
| La encriptación sale del puerto
|--------------------------------------------------------------------------
*/

it('deduce la encriptación del puerto', function (): void {
    $cuenta = CuentaMail::factory()->make(['puerto' => 993]);
    expect($cuenta->encriptacion())->toBe('ssl');

    $cuenta = CuentaMail::factory()->make(['puerto' => 143]);
    expect($cuenta->encriptacion())->toBe('tls');
});
