<?php

declare(strict_types=1);

use App\Enums\EstadoReceta;
use App\Enums\TipoAdjunto;
use App\Models\CuentaMail;
use App\Models\Receta;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15 15:00:00');   // 12:00 en Buenos Aires

    $this->usuario = User::factory()->create([
        'zona_horaria' => 'America/Argentina/Buenos_Aires',
    ]);
});

/**
 * @param  array<string, mixed>  $atributos
 */
function recetaDe(User $usuario, array $atributos = []): Receta
{
    return Receta::factory()->create(['usuario_id' => $usuario->id, ...$atributos]);
}

/*
|--------------------------------------------------------------------------
| La bandeja: qué se puede usar hoy
|--------------------------------------------------------------------------
*/

it('muestra solo las recetas propias', function (): void {
    recetaDe($this->usuario);
    Receta::factory()->create();   // de otro usuario

    $this->actingAs($this->usuario)
        ->get(route('recetas.index'))
        ->assertInertia(fn ($pagina) => $pagina
            ->component('recetas/Index')
            ->has('disponibles', 1)
            ->has('historial', 0));
});

it('las disponibles van de la que vence primero a la que vence último', function (): void {
    /*
     * Es una cuenta regresiva: una receta que vence pasado mañana no puede
     * quedar debajo de una que vale todo el mes. Por eso el orden NO es el de
     * llegada, como en el resto de la app.
     */
    recetaDe($this->usuario, ['asunto' => 'Larga', 'fecha_recepcion' => now()->subDays(1)]);
    recetaDe($this->usuario, ['asunto' => 'Urgente', 'fecha_recepcion' => now()->subDays(28)]);
    recetaDe($this->usuario, ['asunto' => 'Media', 'fecha_recepcion' => now()->subDays(15)]);

    $this->actingAs($this->usuario)
        ->get(route('recetas.index'))
        ->assertInertia(fn ($pagina) => $pagina
            ->where('disponibles.0.asunto', 'Urgente')
            ->where('disponibles.1.asunto', 'Media')
            ->where('disponibles.2.asunto', 'Larga'));
});

it('⚠️ una receta vencida va al historial aunque nadie la haya marcado', function (): void {
    /*
     * "Vencida" no es un estado guardado: se deriva de la fecha. Esta receta
     * sigue en `Disponible` en la base y no tiene que aparecer como usable.
     */
    $vencida = Receta::factory()->vencida()->create(['usuario_id' => $this->usuario->id]);

    expect($vencida->estado)->toBe(EstadoReceta::Disponible);

    $this->actingAs($this->usuario)
        ->get(route('recetas.index'))
        ->assertInertia(fn ($pagina) => $pagina
            ->has('disponibles', 0)
            ->has('historial', 1)
            ->where('historial.0.estaVencida', true));
});

it('una receta usada va al historial con su fecha de uso', function (): void {
    $receta = recetaDe($this->usuario);
    $receta->marcarUsada();

    $this->actingAs($this->usuario)
        ->get(route('recetas.index'))
        ->assertInertia(fn ($pagina) => $pagina
            ->has('disponibles', 0)
            ->where('historial.0.usoVisible', '15/10/2026'));
});

it('los días para vencer se cuentan en días de calendario de la persona', function (): void {
    /*
     * Llegó el 16/9 a las 23:30 de Buenos Aires: con 30 días, vence el 16/10 a
     * las 23:30. Hoy es 15/10 al mediodía, así que faltan ~35 horas, pero lo
     * que la persona necesita leer es "mañana".
     */
    recetaDe($this->usuario, [
        // 16/9 23:30 en Buenos Aires = 17/9 02:30 UTC
        'fecha_recepcion' => '2026-09-17 02:30:00',
        'vigencia_dias' => 30,
    ]);

    $this->actingAs($this->usuario)
        ->get(route('recetas.index'))
        ->assertInertia(fn ($pagina) => $pagina
            ->where('disponibles.0.venceVisible', '16/10/2026')
            ->where('disponibles.0.diasParaVencer', 1));
});

it('⚠️ el contador del mes cuenta en el mes de la persona, no en el de UTC', function (): void {
    /*
     * 31/10 a las 22:00 en Buenos Aires: en UTC ya es 1/11. Una receta que llegó
     * a las 21:30 de ese día es de OCTUBRE para quien la recibió.
     */
    Carbon::setTestNow('2026-11-01 01:00:00');

    // 31/10 21:30 en Buenos Aires -> octubre (aunque en UTC sea noviembre).
    recetaDe($this->usuario, ['fecha_recepcion' => '2026-11-01 00:30:00']);
    // 30/9 22:00 en Buenos Aires -> septiembre (aunque en UTC sea octubre).
    recetaDe($this->usuario, ['fecha_recepcion' => '2026-10-01 01:00:00']);
    // 15/10 -> octubre, sin ambigüedad.
    recetaDe($this->usuario, ['fecha_recepcion' => '2026-10-15 15:00:00']);

    $this->actingAs($this->usuario)
        ->get(route('recetas.index'))
        ->assertInertia(fn ($pagina) => $pagina
            ->where('mes.nombre', 'octubre')
            ->where('mes.llegaron', 2));
});

it('el contador cuenta también las usadas: la pregunta es cuántas llegaron', function (): void {
    recetaDe($this->usuario)->marcarUsada();
    recetaDe($this->usuario);

    $this->actingAs($this->usuario)
        ->get(route('recetas.index'))
        ->assertInertia(fn ($pagina) => $pagina->where('mes.llegaron', 2));
});

it('avisa si todavía no hay casilla configurada', function (): void {
    $this->actingAs($this->usuario)
        ->get(route('recetas.index'))
        ->assertInertia(fn ($pagina) => $pagina->where('tieneCasilla', false));

    CuentaMail::factory()->for($this->usuario, 'usuario')->create();

    $this->actingAs($this->usuario)
        ->get(route('recetas.index'))
        ->assertInertia(fn ($pagina) => $pagina->where('tieneCasilla', true));
});

it('manda los archivos de cada receta para abrirlos en el visor', function (): void {
    $receta = recetaDe($this->usuario);
    $adjunto = $receta->adjuntos()->create([
        'tipo' => TipoAdjunto::Receta,
        'ruta' => 'recetas/x/y.cif',
        'nombre_original' => 'receta.pdf',
        'mime' => 'application/pdf',
        'tamanio_bytes' => 1234,
    ]);

    $this->actingAs($this->usuario)
        ->get(route('recetas.index'))
        ->assertInertia(fn ($pagina) => $pagina
            ->where('disponibles.0.adjuntos.0.nombre', 'receta.pdf')
            ->where('disponibles.0.adjuntos.0.url', route('adjuntos.show', $adjunto)));
});

/*
|--------------------------------------------------------------------------
| Marcar usada
|--------------------------------------------------------------------------
*/

it('marca una receta como usada, con la fecha de hoy', function (): void {
    $receta = recetaDe($this->usuario);

    $this->actingAs($this->usuario)
        ->put(route('recetas.uso', $receta), ['usada' => '1'])
        ->assertSessionHas('exito');

    expect($receta->fresh()->estado)->toBe(EstadoReceta::Usada)
        ->and($receta->fresh()->fecha_uso?->toDateTimeString())->toBe('2026-10-15 15:00:00');
});

it('deshace la marca de uso y borra la fecha', function (): void {
    $receta = recetaDe($this->usuario);
    $receta->marcarUsada();

    $this->actingAs($this->usuario)
        ->put(route('recetas.uso', $receta), ['usada' => '0'])
        ->assertSessionHas('exito');

    expect($receta->fresh()->estado)->toBe(EstadoReceta::Disponible)
        ->and($receta->fresh()->fecha_uso)->toBeNull();
});

it('deshacer el uso NO le devuelve la vigencia a una receta vencida', function (): void {
    // Lo que se deshace es la marca, no el paso del tiempo.
    $receta = Receta::factory()->vencida()->create(['usuario_id' => $this->usuario->id]);
    $receta->marcarUsada();
    $receta->volverADisponible();

    expect($receta->fresh()->estaVencida())->toBeTrue()
        ->and($receta->fresh()->estaDisponible())->toBeFalse();
});

it('acepta el "on" de un checkbox real', function (): void {
    // La regla del checkbox de CLAUDE.md: el navegador manda "on", no true.
    $receta = recetaDe($this->usuario);

    $this->actingAs($this->usuario)
        ->put(route('recetas.uso', $receta), ['usada' => 'on'])
        ->assertSessionHasNoErrors();

    expect($receta->fresh()->estado)->toBe(EstadoReceta::Usada);
});

/*
|--------------------------------------------------------------------------
| Vigencia
|--------------------------------------------------------------------------
*/

it('cambia la vigencia, y con ella el vencimiento', function (): void {
    $receta = recetaDe($this->usuario, ['fecha_recepcion' => '2026-10-01 12:00:00', 'vigencia_dias' => 30]);

    $this->actingAs($this->usuario)
        ->put(route('recetas.update', $receta), ['vigencia_dias' => '90'])
        ->assertSessionHas('exito');

    expect($receta->fresh()->vigencia_dias)->toBe(90)
        ->and($receta->fresh()->vence()->toDateString())->toBe('2026-12-30');
});

it('una receta vencida vuelve a estar disponible si se le amplía la vigencia', function (): void {
    // El caso real: una receta de crónico que vale 90 días y entró con el default de 30.
    $receta = Receta::factory()->vencida()->create(['usuario_id' => $this->usuario->id]);

    $this->actingAs($this->usuario)
        ->put(route('recetas.update', $receta), ['vigencia_dias' => '90']);

    expect($receta->fresh()->estaDisponible())->toBeTrue();
});

it('rechaza una vigencia que no puede existir', function (mixed $valor): void {
    $receta = recetaDe($this->usuario);

    $this->actingAs($this->usuario)
        ->put(route('recetas.update', $receta), ['vigencia_dias' => $valor])
        ->assertSessionHasErrors('vigencia_dias');
})->with([
    'cero días' => ['0'],
    'un tipeo de más de un año' => ['300000'],
    'texto' => ['treinta'],
    'decimales' => ['30,5'],
    'vacía' => [''],
]);

/*
|--------------------------------------------------------------------------
| Borrar
|--------------------------------------------------------------------------
*/

it('borrar manda la receta a la papelera, que es lo que impide reimportarla', function (): void {
    /*
     * Una receta en la papelera sigue ocupando su `message_id_hash`, y la
     * deduplicación del importador mira la papelera: lo que entró y no era una
     * receta (una promoción) no vuelve en la próxima corrida.
     */
    $receta = recetaDe($this->usuario);

    $this->actingAs($this->usuario)
        ->delete(route('recetas.destroy', $receta))
        ->assertSessionHas('exito');

    expect(Receta::count())->toBe(0)
        ->and(Receta::withTrashed()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Autorización
|--------------------------------------------------------------------------
*/

it('no deja tocar la receta de otro', function (): void {
    $ajena = Receta::factory()->create();

    $this->actingAs($this->usuario)
        ->put(route('recetas.uso', $ajena), ['usada' => '1'])
        ->assertForbidden();

    $this->actingAs($this->usuario)
        ->put(route('recetas.update', $ajena), ['vigencia_dias' => '60'])
        ->assertForbidden();

    $this->actingAs($this->usuario)
        ->delete(route('recetas.destroy', $ajena))
        ->assertForbidden();

    expect($ajena->fresh()->estado)->toBe(EstadoReceta::Disponible)
        ->and($ajena->fresh()->vigencia_dias)->toBe(30);
});

it('⚠️ autoriza ANTES de validar: a otro no le confirma que la receta existe', function (): void {
    // Con una vigencia inválida, sin autorizar primero contestaría con un error
    // de validación en vez de un 403.
    $ajena = Receta::factory()->create();

    $this->actingAs($this->usuario)
        ->put(route('recetas.update', $ajena), ['vigencia_dias' => 'nada'])
        ->assertForbidden();
});

it('no deja abrir el archivo de una receta ajena', function (): void {
    $ajena = Receta::factory()->create();
    $adjunto = $ajena->adjuntos()->create([
        'tipo' => TipoAdjunto::Receta,
        'ruta' => 'recetas/x/y.cif',
        'nombre_original' => 'receta.pdf',
        'mime' => 'application/pdf',
        'tamanio_bytes' => 10,
    ]);

    $this->actingAs($this->usuario)
        ->get(route('adjuntos.show', $adjunto))
        ->assertForbidden();
});

it('pide sesión', function (): void {
    $this->get(route('recetas.index'))->assertRedirect(route('login'));
});
