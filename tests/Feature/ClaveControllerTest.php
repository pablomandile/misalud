<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Models\Clave;
use App\Models\Paciente;
use App\Models\User;
use App\Services\ExportadorDeDatos;
use Illuminate\Support\Facades\DB;

/** Una clave larga y distintiva: con una corta, cualquier texto la "contiene". */
const CANARIO_CLAVE = 'Canario-Clave-9f3Kq!zW7';

/**
 * @return array{0: User, 1: Paciente}
 */
function duenioConFicha(): array
{
    $duenio = User::factory()->create();
    $paciente = Paciente::factory()->for($duenio, 'usuario')->create(['nombre' => 'Mamá']);

    return [$duenio, $paciente];
}

/**
 * @return array<string, int>
 */
function claveConfirmada(): array
{
    return ['auth.password_confirmed_at' => time()];
}

it('guarda una clave en la ficha', function (): void {
    [$duenio, $paciente] = duenioConFicha();

    $this->actingAs($duenio)
        ->post(route('pacientes.claves.store', $paciente), [
            'sitio' => 'Portal OSDE',
            'usuario' => 'mama@ejemplo.com',
            'clave' => CANARIO_CLAVE,
            'notas' => 'Pide código por SMS',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $clave = $paciente->claves()->sole();

    expect($clave->sitio)->toBe('Portal OSDE')
        ->and($clave->clave)->toBe(CANARIO_CLAVE);
});

it('exige el sitio y la clave al crear', function (): void {
    [$duenio, $paciente] = duenioConFicha();

    $this->actingAs($duenio)
        ->post(route('pacientes.claves.store', $paciente), ['sitio' => '', 'clave' => ''])
        ->assertSessionHasErrors(['sitio', 'clave']);
});

it('al editar, la clave vacía deja la que estaba', function (): void {
    [$duenio, $paciente] = duenioConFicha();
    $clave = Clave::factory()->for($paciente)->create(['clave' => CANARIO_CLAVE]);

    $this->actingAs($duenio)
        ->put(route('claves.update', $clave), ['sitio' => 'Laboratorio', 'clave' => ''])
        ->assertSessionHasNoErrors();

    expect($clave->fresh()->sitio)->toBe('Laboratorio')
        ->and($clave->fresh()->clave)->toBe(CANARIO_CLAVE);
});

it('al editar con una clave nueva, la cambia', function (): void {
    [$duenio, $paciente] = duenioConFicha();
    $clave = Clave::factory()->for($paciente)->create();

    $this->actingAs($duenio)
        ->put(route('claves.update', $clave), ['sitio' => 'Laboratorio', 'clave' => CANARIO_CLAVE])
        ->assertSessionHasNoErrors();

    expect($clave->fresh()->clave)->toBe(CANARIO_CLAVE);
});

it('borrar borra de verdad', function (): void {
    [$duenio, $paciente] = duenioConFicha();
    $clave = Clave::factory()->for($paciente)->create();

    $this->actingAs($duenio)->delete(route('claves.destroy', $clave))->assertRedirect();

    expect(DB::table('claves')->where('id', $clave->id)->exists())->toBeFalse();
});

it('⚠️ el listado no manda la clave en ningún prop', function (): void {
    [$duenio, $paciente] = duenioConFicha();
    Clave::factory()->for($paciente)->create(['sitio' => 'Portal OSDE', 'clave' => CANARIO_CLAVE]);

    $respuesta = $this->actingAs($duenio)->withSession(claveConfirmada())->get(route('claves.index'));

    $respuesta->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('claves/Index')
            ->where('pacientes.0.claves.0.sitio', 'Portal OSDE')
            ->where('desbloqueado', true)
        );

    expect($respuesta->getContent())->not->toContain(CANARIO_CLAVE);
});

it('el listado avisa que está bloqueado sin una confirmación reciente', function (): void {
    [$duenio] = duenioConFicha();

    $this->actingAs($duenio)
        ->withSession(['auth.password_confirmed_at' => time() - 3600])
        ->get(route('claves.index'))
        ->assertInertia(fn ($p) => $p->where('desbloqueado', false));
});

it('revelar sin confirmar la contraseña contesta 423 y no la clave', function (): void {
    [$duenio, $paciente] = duenioConFicha();
    $clave = Clave::factory()->for($paciente)->create(['clave' => CANARIO_CLAVE]);

    $respuesta = $this->actingAs($duenio)->getJson(route('claves.revelar', $clave));

    $respuesta->assertStatus(423);
    expect($respuesta->getContent())->not->toContain(CANARIO_CLAVE);
});

it('una confirmación de hace más de cinco minutos ya no alcanza', function (): void {
    [$duenio, $paciente] = duenioConFicha();
    $clave = Clave::factory()->for($paciente)->create();

    $this->actingAs($duenio)
        ->withSession(['auth.password_confirmed_at' => time() - 600])
        ->getJson(route('claves.revelar', $clave))
        ->assertStatus(423);
});

it('revelar con la contraseña confirmada devuelve la clave, sin caché', function (): void {
    [$duenio, $paciente] = duenioConFicha();
    $clave = Clave::factory()->for($paciente)->create(['clave' => CANARIO_CLAVE]);

    $respuesta = $this->actingAs($duenio)->withSession(claveConfirmada())->getJson(route('claves.revelar', $clave));

    $respuesta->assertOk()->assertExactJson(['clave' => CANARIO_CLAVE]);
    expect($respuesta->headers->get('Cache-Control'))->toContain('no-store');
});

it('desbloquear sin confirmar manda a confirmar la contraseña', function (): void {
    [$duenio] = duenioConFicha();

    $this->actingAs($duenio)
        ->get(route('claves.desbloquear'))
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($duenio)
        ->withSession(claveConfirmada())
        ->get(route('claves.desbloquear'))
        ->assertRedirect(route('claves.index'));
});

it('un cuidador puede revelar; un lector y un extraño no', function (): void {
    [, $paciente] = duenioConFicha();
    $clave = Clave::factory()->for($paciente)->create(['clave' => CANARIO_CLAVE]);

    $cuidador = User::factory()->create();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($cuidador)->withSession(claveConfirmada())
        ->getJson(route('claves.revelar', $clave))->assertOk();
    $this->actingAs($lector)->withSession(claveConfirmada())
        ->getJson(route('claves.revelar', $clave))->assertForbidden();
    $this->actingAs(User::factory()->create())->withSession(claveConfirmada())
        ->getJson(route('claves.revelar', $clave))->assertForbidden();
});

it('un lector ve el sitio pero no puede agregar, editar ni borrar', function (): void {
    [, $paciente] = duenioConFicha();
    $clave = Clave::factory()->for($paciente)->create(['sitio' => 'Laboratorio']);
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $this->actingAs($lector)->get(route('claves.index'))
        ->assertInertia(fn ($p) => $p
            ->where('pacientes.0.claves.0.sitio', 'Laboratorio')
            ->where('pacientes.0.puedeEditar', false)
        );

    $this->actingAs($lector)->put(route('claves.update', $clave), ['sitio' => 'X'])->assertForbidden();
    $this->actingAs($lector)->delete(route('claves.destroy', $clave))->assertForbidden();
    $this->actingAs($lector)
        ->post(route('pacientes.claves.store', $paciente), ['sitio' => 'X', 'clave' => 'y'])
        ->assertForbidden();
});

it('una cuenta de Google sin contraseña revela sin confirmar', function (): void {
    $usuario = User::factory()->create(['password' => null]);
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $clave = Clave::factory()->for($paciente)->create(['clave' => CANARIO_CLAVE]);

    $this->actingAs($usuario)->getJson(route('claves.revelar', $clave))
        ->assertOk()
        ->assertExactJson(['clave' => CANARIO_CLAVE]);
});

it('las cuatro columnas quedan ilegibles en la base', function (): void {
    [, $paciente] = duenioConFicha();
    $clave = Clave::factory()->for($paciente)->create([
        'sitio' => 'Portal-Sitio-Distintivo',
        'usuario' => 'usuario-distintivo@ejemplo.com',
        'clave' => CANARIO_CLAVE,
        'notas' => 'Nota-Distintiva',
    ]);

    $fila = (array) DB::table('claves')->where('id', $clave->id)->first();

    expect($fila['sitio'])->not->toContain('Portal-Sitio-Distintivo')
        ->and($fila['usuario'])->not->toContain('usuario-distintivo')
        ->and($fila['clave'])->not->toContain(CANARIO_CLAVE)
        ->and($fila['notas'])->not->toContain('Nota-Distintiva');
});

it('serializar el modelo entero no expone la clave', function (): void {
    $clave = Clave::factory()->create(['clave' => CANARIO_CLAVE]);

    expect(json_encode($clave))->not->toContain(CANARIO_CLAVE);
});

it('borrar al paciente se lleva sus claves', function (): void {
    [, $paciente] = duenioConFicha();
    Clave::factory()->for($paciente)->create();

    $paciente->forceDelete();

    expect(DB::table('claves')->count())->toBe(0);
});

it('la descarga de datos trae las claves descifradas, solo de las fichas propias', function (): void {
    [$duenio, $paciente] = duenioConFicha();
    Clave::factory()->for($paciente)->create(['sitio' => 'Portal OSDE', 'clave' => CANARIO_CLAVE]);

    $ajena = Paciente::factory()->create();
    $ajena->cuidadores()->attach($duenio, ['rol' => RolPaciente::Cuidador->value]);
    Clave::factory()->for($ajena)->create(['clave' => 'Clave-De-Otra-Ficha-77']);

    $ruta = app(ExportadorDeDatos::class)->armar($duenio);

    $zip = new ZipArchive;
    $zip->open($ruta);
    $datos = (string) $zip->getFromName('datos.json');
    $zip->close();
    @unlink($ruta);

    // json_encode escapa el `!` y el `/`, no las letras: se busca sin escapes.
    expect(json_decode($datos, true))->not->toBeNull()
        ->and($datos)->toContain('Portal OSDE')
        ->and(str_contains(json_encode(json_decode($datos, true)), json_encode(CANARIO_CLAVE)))->toBeTrue()
        ->and($datos)->not->toContain('Clave-De-Otra-Ficha-77');
});
