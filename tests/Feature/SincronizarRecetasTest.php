<?php

declare(strict_types=1);

use App\Enums\TipoAdjunto;
use App\Models\CuentaMail;
use App\Models\Receta;
use App\Models\User;
use App\Services\LectorDeCasilla;
use App\Support\AdjuntoDeCasilla;
use App\Support\MensajeDeCasilla;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Todo esto corre SIN RED
|--------------------------------------------------------------------------
|
| `LectorDeCasilla` es la única pieza que habla IMAP y lo único que hace es
| producir `MensajeDeCasilla`. Acá se lo reemplaza por un doble, y así se pueden
| probar de verdad las decisiones del paso -ventana, solapamiento, filtros,
| deduplicación, qué adjunto entra- en vez de dejarlas sin cubrir porque
| "necesitan una casilla".
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-10 12:00:00');
    Storage::fake('local');
});

/** Un PDF mínimo pero que finfo reconoce como `application/pdf`. */
function pdfDeUnaReceta(string $texto = 'receta'): string
{
    return "%PDF-1.4\n1 0 obj\n<</Type/Catalog>>\nendobj\ntrailer<</Root 1 0 R>>\n%%EOF\n{$texto}";
}

/**
 * @param  list<AdjuntoDeCasilla>  $adjuntos
 */
function mailDe(
    string $id = '<uno@farmacia.test>',
    string $remitente = 'recetas@farmacia.com.ar',
    ?string $fecha = null,
    ?array $adjuntos = null,
    ?string $asunto = 'Tu receta',
): MensajeDeCasilla {
    return new MensajeDeCasilla(
        identificador: $id,
        remitente: $remitente,
        asunto: $asunto,
        fecha: CarbonImmutable::parse($fecha ?? '2026-10-09 09:00:00'),
        adjuntos: $adjuntos ?? [new AdjuntoDeCasilla('receta.pdf', pdfDeUnaReceta())],
    );
}

/**
 * Pone el doble del lector y devuelve lo que se le pidió, para poder afirmar
 * sobre la ventana.
 *
 * @param  list<MensajeDeCasilla>  $mensajes
 * @return object{desde: ?CarbonImmutable, tope: ?int}
 */
function lectorQueDevuelve(array $mensajes): object
{
    $pedido = new class
    {
        public ?CarbonImmutable $desde = null;

        public ?int $tope = null;
    };

    $doble = Mockery::mock(LectorDeCasilla::class);
    $doble->shouldReceive('mensajesDesde')
        ->andReturnUsing(function (CuentaMail $cuenta, CarbonImmutable $desde, int $tope) use ($mensajes, $pedido): array {
            $pedido->desde = $desde;
            $pedido->tope = $tope;

            return $mensajes;
        });

    app()->instance(LectorDeCasilla::class, $doble);

    return $pedido;
}

/**
 * @param  array<string, mixed>  $atributos
 */
function casillaDePrueba(array $atributos = []): CuentaMail
{
    return CuentaMail::factory()
        ->for(User::factory()->create(), 'usuario')
        ->create($atributos);
}

/*
|--------------------------------------------------------------------------
| Lo que entra y lo que no
|--------------------------------------------------------------------------
*/

it('importa un mail con su adjunto', function (): void {
    $casilla = casillaDePrueba();
    lectorQueDevuelve([mailDe()]);

    $this->artisan('misalud:sincronizar-recetas')->assertSuccessful();

    $receta = Receta::sole();

    expect($receta->usuario_id)->toBe($casilla->usuario_id)
        ->and($receta->cuenta_mail_id)->toBe($casilla->id)
        ->and($receta->remitente)->toBe('recetas@farmacia.com.ar')
        ->and($receta->asunto)->toBe('Tu receta')
        ->and($receta->estado->value)->toBe('disponible')
        ->and($receta->vigencia_dias)->toBe(30)
        // La fecha del MAIL, no la de la importación: si el servidor estuvo
        // caído, la receta no gana días de vida.
        ->and($receta->fecha_recepcion->toDateTimeString())->toBe('2026-10-09 09:00:00');

    $adjunto = $receta->adjuntos()->sole();

    expect($adjunto->tipo)->toBe(TipoAdjunto::Receta)
        ->and($adjunto->nombre_original)->toBe('receta.pdf')
        ->and($adjunto->mime)->toBe('application/pdf');
});

it('guarda el archivo CIFRADO en el disco privado', function (): void {
    casillaDePrueba();
    lectorQueDevuelve([mailDe()]);

    $this->artisan('misalud:sincronizar-recetas');

    $ruta = Receta::sole()->adjuntos()->sole()->ruta;

    expect(Storage::disk('local')->exists($ruta))->toBeTrue()
        ->and(Storage::disk('local')->get($ruta))->not->toContain('%PDF')
        ->and($ruta)->toEndWith('.cif');
});

it('⚠️ un mail SIN adjuntos no es una receta', function (): void {
    /*
     * "Tu receta está lista, entrá a nuestro sitio" no trae ninguna. Guardar una
     * fila por ese mail llenaría la bandeja de recetas que no se pueden mostrar.
     */
    casillaDePrueba();
    lectorQueDevuelve([mailDe(adjuntos: [])]);

    $this->artisan('misalud:sincronizar-recetas')->assertSuccessful();

    expect(Receta::count())->toBe(0);
});

it('⚠️ un adjunto de un tipo que no se acepta tampoco alcanza', function (): void {
    // El caso real es el logotipo de la firma, o un .docx con instrucciones.
    casillaDePrueba();
    lectorQueDevuelve([mailDe(adjuntos: [new AdjuntoDeCasilla('instructivo.docx', 'PK'.str_repeat('x', 60))])]);

    $this->artisan('misalud:sincronizar-recetas');

    expect(Receta::count())->toBe(0);
});

it('descarta de a UN adjunto, no el mail entero', function (): void {
    /*
     * Si el mail trae la receta en PDF y además un instructivo que no se acepta,
     * se importa la receta. Descartar el mail entero perdería lo único que
     * importaba.
     */
    casillaDePrueba();
    lectorQueDevuelve([mailDe(adjuntos: [
        new AdjuntoDeCasilla('instructivo.docx', 'PK'.str_repeat('x', 60)),
        new AdjuntoDeCasilla('receta.pdf', pdfDeUnaReceta()),
    ])]);

    $this->artisan('misalud:sincronizar-recetas');

    expect(Receta::count())->toBe(1)
        ->and(Receta::sole()->adjuntos()->count())->toBe(1)
        ->and(Receta::sole()->adjuntos()->sole()->nombre_original)->toBe('receta.pdf');
});

/*
|--------------------------------------------------------------------------
| Deduplicación
|--------------------------------------------------------------------------
*/

it('no importa dos veces el mismo mail', function (): void {
    // Es lo que hace que el solapamiento de la ventana sea gratis.
    casillaDePrueba();
    lectorQueDevuelve([mailDe()]);

    $this->artisan('misalud:sincronizar-recetas');
    $this->artisan('misalud:sincronizar-recetas');
    $this->artisan('misalud:sincronizar-recetas');

    expect(Receta::count())->toBe(1)
        // Y tampoco deja copias del archivo dando vueltas.
        ->and(count(Storage::disk('local')->allFiles()))->toBe(1);
});

it('⚠️ la deduplicación ve la PAPELERA', function (): void {
    /*
     * El UNIQUE de la base no sabe de soft deletes: una receta borrada sigue
     * ocupando su hash. Sin `withTrashed()` el `create()` se estrellaría contra
     * la base en vez de contarse como repetida.
     */
    casillaDePrueba();
    lectorQueDevuelve([mailDe()]);

    $this->artisan('misalud:sincronizar-recetas');
    Receta::sole()->delete();

    $this->artisan('misalud:sincronizar-recetas')->assertSuccessful();

    expect(Receta::withTrashed()->count())->toBe(1)
        ->and(Receta::count())->toBe(0);
});

it('dos usuarios distintos SÍ pueden importar el mismo mail', function (): void {
    /*
     * El plan pedía un UNIQUE global sobre `message_id_hash`. Eso tiene este bug:
     * si la farmacia le manda el mismo mail a dos personas que las dos usan
     * MiSalud, la segunda no podría importarlo nunca -y en silencio, porque
     * parece "ya estaba"-.
     */
    casillaDePrueba();
    casillaDePrueba();
    lectorQueDevuelve([mailDe()]);

    $this->artisan('misalud:sincronizar-recetas')->assertSuccessful();

    expect(Receta::count())->toBe(2);
});

it('un mail sin Message-ID igual se deduplica', function (): void {
    /*
     * Sin un reemplazo determinístico, su hash queda nulo; MySQL admite todos los
     * nulos que quiera en un UNIQUE, y ese mail se reimportaría cada hora para
     * siempre, escribiendo otra copia del archivo cada vez.
     */
    $fecha = CarbonImmutable::parse('2026-10-09 09:00:00');

    $primero = MensajeDeCasilla::identificadorPara(null, $fecha, 'a@b.com', 'Receta');
    $otraVez = MensajeDeCasilla::identificadorPara('', $fecha, 'a@b.com', 'Receta');
    $distinto = MensajeDeCasilla::identificadorPara(null, $fecha, 'a@b.com', 'Otra cosa');

    expect($primero)->toStartWith('sin-message-id:')
        ->and($otraVez)->toBe($primero)
        ->and($distinto)->not->toBe($primero);
});

/*
|--------------------------------------------------------------------------
| Filtros por remitente
|--------------------------------------------------------------------------
*/

it('sin filtros entra todo', function (): void {
    casillaDePrueba(['filtros' => null]);
    lectorQueDevuelve([mailDe(remitente: 'cualquiera@otracosa.com')]);

    $this->artisan('misalud:sincronizar-recetas');

    expect(Receta::count())->toBe(1);
});

it('filtra por dirección exacta', function (): void {
    casillaDePrueba(['filtros' => ['recetas@farmacia.com.ar']]);
    lectorQueDevuelve([
        mailDe(id: '<si@x.test>', remitente: 'recetas@farmacia.com.ar'),
        mailDe(id: '<no@x.test>', remitente: 'promos@farmacia.com.ar'),
    ]);

    $this->artisan('misalud:sincronizar-recetas');

    expect(Receta::count())->toBe(1)
        ->and(Receta::sole()->remitente)->toBe('recetas@farmacia.com.ar');
});

it('un filtro de dominio acepta cualquier dirección de ese dominio', function (): void {
    casillaDePrueba(['filtros' => ['osde.com.ar']]);
    lectorQueDevuelve([
        mailDe(id: '<a@x.test>', remitente: 'noreply@osde.com.ar'),
        mailDe(id: '<b@x.test>', remitente: 'avisos@osde.com.ar'),
        mailDe(id: '<c@x.test>', remitente: 'otro@swiss.com.ar'),
    ]);

    $this->artisan('misalud:sincronizar-recetas');

    expect(Receta::count())->toBe(2);
});

it('un filtro de dominio también acepta subdominios', function (): void {
    // Una obra social grande manda desde `avisos.osde.com.ar`, y quien escribió
    // `osde.com.ar` quiso decir eso.
    casillaDePrueba(['filtros' => ['osde.com.ar']]);
    lectorQueDevuelve([mailDe(remitente: 'noreply@avisos.osde.com.ar')]);

    $this->artisan('misalud:sincronizar-recetas');

    expect(Receta::count())->toBe(1);
});

it('un filtro NO acepta un dominio que apenas termina parecido', function (): void {
    // `no-osde.com.ar` no es un subdominio de `osde.com.ar`.
    casillaDePrueba(['filtros' => ['osde.com.ar']]);
    lectorQueDevuelve([mailDe(remitente: 'x@no-osde.com.ar')]);

    $this->artisan('misalud:sincronizar-recetas');

    expect(Receta::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| La ventana y su marca
|--------------------------------------------------------------------------
*/

it('la primera corrida mira dias_iniciales hacia atrás', function (): void {
    config(['misalud.recetas.dias_iniciales' => 60]);
    casillaDePrueba();
    $pedido = lectorQueDevuelve([]);

    $this->artisan('misalud:sincronizar-recetas');

    expect($pedido->desde?->toDateString())->toBe('2026-08-11');   // 60 días antes
});

it('⚠️ las siguientes miran desde la última marca MENOS el solapamiento', function (): void {
    /*
     * No es paranoia: el `SINCE` de IMAP compara por día y un mail puede
     * entregarse tarde. Reimportar es gratis -lo frena el UNIQUE-, así que de más
     * no cuesta nada y de menos es un agujero que nadie nota.
     *
     * Y NO es "desde el día 1 del mes", que es lo que decía el plan: con ese
     * criterio una receta del 31 de enero desaparece el 1 de febrero aunque nadie
     * la haya usado.
     */
    config(['misalud.recetas.dias_de_solapamiento' => 7]);
    casillaDePrueba(['sincronizado_hasta' => '2026-10-09 10:00:00']);
    $pedido = lectorQueDevuelve([]);

    $this->artisan('misalud:sincronizar-recetas');

    expect($pedido->desde?->toDateString())->toBe('2026-10-02');
});

it('la marca va al INICIO de la corrida cuando se vio toda la ventana', function (): void {
    // Al inicio y no a `now()`: un mail que llegó mientras corríamos no puede
    // quedar del lado ya revisado.
    $casilla = casillaDePrueba();
    lectorQueDevuelve([mailDe()]);

    $this->artisan('misalud:sincronizar-recetas');

    expect($casilla->fresh()->sincronizado_hasta?->toDateTimeString())
        ->toBe('2026-10-10 12:00:00');
});

it('⚠️ si se truncó por el tope, la marca va al último mail mirado', function (): void {
    /*
     * Es lo que hace avanzar a una casilla con mucho correo acumulado. Si la marca
     * fuera al inicio de la corrida, todo lo que quedó sin mirar caería fuera de
     * la próxima ventana y se perdería; y si no se moviera, la corrida siguiente
     * traería los mismos y la casilla no avanzaría nunca.
     */
    config(['misalud.recetas.tope_por_corrida' => 2]);
    $casilla = casillaDePrueba();
    lectorQueDevuelve([
        mailDe(id: '<a@x.test>', fecha: '2026-09-01 08:00:00'),
        mailDe(id: '<b@x.test>', fecha: '2026-09-02 08:00:00'),
    ]);

    $this->artisan('misalud:sincronizar-recetas');

    expect($casilla->fresh()->sincronizado_hasta?->toDateTimeString())
        ->toBe('2026-09-02 08:00:00');
});

it('⚠️ la marca NUNCA va hacia atrás', function (): void {
    /*
     * Cuando todos los mails de la ventana están en la zona de solapamiento, la
     * fecha del último es ANTERIOR a la marca actual. Moverla ahí agrandaría la
     * ventana en cada corrida hasta volver a mirar la casilla entera.
     */
    config(['misalud.recetas.tope_por_corrida' => 1]);
    $casilla = casillaDePrueba(['sincronizado_hasta' => '2026-10-09 10:00:00']);
    lectorQueDevuelve([mailDe(fecha: '2026-10-05 08:00:00')]);

    $this->artisan('misalud:sincronizar-recetas');

    expect($casilla->fresh()->sincronizado_hasta?->toDateTimeString())
        ->toBe('2026-10-09 10:00:00');
});

/*
|--------------------------------------------------------------------------
| Aislamiento de fallos
|--------------------------------------------------------------------------
*/

it('⚠️ una casilla caída no se lleva a las demás', function (): void {
    /*
     * El caso más probable de todos: una contraseña de aplicación revocada -se
     * revocan solas cuando alguien cambia la clave de su cuenta de Google-.
     */
    $rota = casillaDePrueba();
    $sana = casillaDePrueba();

    $doble = Mockery::mock(LectorDeCasilla::class);
    $doble->shouldReceive('mensajesDesde')
        ->andReturnUsing(function (CuentaMail $cuenta) use ($rota): array {
            if ($cuenta->id === $rota->id) {
                throw new RuntimeException('login rechazado');
            }

            return [mailDe()];
        });
    app()->instance(LectorDeCasilla::class, $doble);

    // Devuelve FAILURE -algo falló- pero la casilla sana igual importó.
    $this->artisan('misalud:sincronizar-recetas')->assertFailed();

    expect(Receta::count())->toBe(1)
        ->and(Receta::sole()->cuenta_mail_id)->toBe($sana->id)
        ->and($sana->fresh()->sincronizado_hasta)->not->toBeNull()
        ->and($rota->fresh()->sincronizado_hasta)->toBeNull();
});

it('⚠️ el mensaje del error NO se imprime: puede venir del servidor IMAP', function (): void {
    casillaDePrueba();

    $doble = Mockery::mock(LectorDeCasilla::class);
    $doble->shouldReceive('mensajesDesde')
        ->andThrow(new RuntimeException('NO [AUTHENTICATIONFAILED] algo del comando LOGIN'));
    app()->instance(LectorDeCasilla::class, $doble);

    $this->artisan('misalud:sincronizar-recetas')
        ->doesntExpectOutputToContain('AUTHENTICATIONFAILED')
        ->assertFailed();
});

/*
|--------------------------------------------------------------------------
| El ensayo en seco
|--------------------------------------------------------------------------
*/

it('--seco no guarda nada ni mueve la marca', function (): void {
    $casilla = casillaDePrueba();
    lectorQueDevuelve([mailDe()]);

    $this->artisan('misalud:sincronizar-recetas --seco')->assertSuccessful();

    expect(Receta::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($casilla->fresh()->sincronizado_hasta)->toBeNull();
});

it('--casilla sincroniza una sola', function (): void {
    $una = casillaDePrueba();
    $otra = casillaDePrueba();
    lectorQueDevuelve([mailDe()]);

    $this->artisan('misalud:sincronizar-recetas --casilla='.$una->id);

    expect($una->fresh()->sincronizado_hasta)->not->toBeNull()
        ->and($otra->fresh()->sincronizado_hasta)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Vencimiento derivado
|--------------------------------------------------------------------------
*/

it('"vencida" se deriva de la fecha, no se guarda', function (): void {
    $reciente = Receta::factory()->create(['fecha_recepcion' => now()->subDays(5), 'vigencia_dias' => 30]);
    $vieja = Receta::factory()->vencida()->create();

    expect($reciente->estaVencida())->toBeFalse()
        ->and($reciente->estaDisponible())->toBeTrue()
        ->and($vieja->estaVencida())->toBeTrue()
        ->and($vieja->estaDisponible())->toBeFalse()
        // Y no hay ninguna columna que lo diga.
        ->and(DB::getSchemaBuilder()->hasColumn('recetas', 'vencida'))->toBeFalse();
});

it('una receta usada no está disponible aunque no haya vencido', function (): void {
    $usada = Receta::factory()->usada()->create(['fecha_recepcion' => now()]);

    expect($usada->estaVencida())->toBeFalse()
        ->and($usada->estaDisponible())->toBeFalse();
});

it('el vencimiento se cuenta desde que llegó el mail', function (): void {
    $receta = Receta::factory()->create([
        'fecha_recepcion' => '2026-10-01 08:00:00',
        'vigencia_dias' => 30,
    ]);

    expect($receta->vence()->toDateString())->toBe('2026-10-31');
});

/*
|--------------------------------------------------------------------------
| El cifrado
|--------------------------------------------------------------------------
*/

it('guarda cifrados el remitente, el asunto y el message id', function (): void {
    casillaDePrueba();
    lectorQueDevuelve([mailDe()]);

    $this->artisan('misalud:sincronizar-recetas');

    $fila = DB::table('recetas')->sole();

    expect($fila->remitente)->not->toBe('recetas@farmacia.com.ar')
        ->and($fila->asunto)->not->toBe('Tu receta')
        ->and($fila->message_id)->not->toBe('<uno@farmacia.test>')
        // Y la fecha SÍ en claro: es por donde ordena la bandeja.
        ->and($fila->fecha_recepcion)->toBe('2026-10-09 09:00:00');
});
