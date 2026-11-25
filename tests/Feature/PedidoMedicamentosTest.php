<?php

declare(strict_types=1);

use App\Enums\EstadoEnvio;
use App\Enums\RolPaciente;
use App\Mail\PedidoDeMedicamentos;
use App\Models\Contacto;
use App\Models\Envio;
use App\Models\Medicamento;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Tratamiento;
use App\Models\User;
use App\Support\PlantillaDePedido;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Pedido de medicamentos: por mail, con un mensaje por medicamento
|--------------------------------------------------------------------------
*/

/**
 * @return array{0: User, 1: Paciente, 2: Tratamiento, 3: Medico}
 */
function fichaParaPedido(): array
{
    $usuario = User::factory()->create(['name' => 'Pablo']);
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Marta']);
    $medicamento = Medicamento::factory()->for($usuario, 'usuario')->create(['nombre_comercial' => 'Losartán']);
    $tratamiento = Tratamiento::factory()->create([
        'paciente_id' => $paciente->id,
        'medicamento_id' => $medicamento->id,
        'dosis' => '50 mg',
    ]);
    $medico = Medico::factory()->for($usuario, 'usuario')->create(['nombre' => 'Dra. López', 'email' => 'lopez@example.com']);

    return [$usuario, $paciente, $tratamiento, $medico];
}

it('manda el pedido al médico con el mensaje y la lista, y queda en el historial', function (): void {
    Mail::fake();
    [$usuario, $paciente, $tratamiento, $medico] = fichaParaPedido();

    $this->actingAs($usuario)
        ->post(route('pedidos.store'), [
            'paciente_id' => $paciente->id,
            'pedidos' => [['tratamiento_id' => $tratamiento->id, 'mensaje' => 'Hola, necesito la receta para Marta.']],
            'destinatario' => "medico:{$medico->id}",
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('exito', 'Se mandó el pedido a Dra. López.');

    Mail::assertSent(PedidoDeMedicamentos::class, function (PedidoDeMedicamentos $mail) use ($usuario): bool {
        $texto = $mail->render();

        return $mail->hasTo('lopez@example.com')
            && $mail->hasReplyTo($usuario->email)
            && $mail->hasSubject('Pedido de medicamentos')
            && str_contains($texto, "- Losartán · 50 mg\nHola, necesito la receta para Marta.");
    });

    $envio = Envio::sole();
    expect($envio->estado)->toBe(EstadoEnvio::Enviado)
        ->and($envio->destinatario)->toBe('lopez@example.com')
        ->and($envio->cuerpo)->toContain('- Losartán · 50 mg');
});

it('guarda el mensaje de cada medicamento como su plantilla cifrada y lo precarga la próxima vez', function (): void {
    Mail::fake();
    [$usuario, $paciente, $tratamiento, $medico] = fichaParaPedido();
    $otro = Tratamiento::factory()->create([
        'paciente_id' => $paciente->id,
        'medicamento_id' => Medicamento::factory()->for($usuario, 'usuario')->create(['nombre_comercial' => 'Atorvastatina'])->id,
        'dosis' => '20 mg',
    ]);

    $this->actingAs($usuario)
        ->get(route('pedidos.index', ['paciente' => $paciente->id]))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('pedidos/Index')
            ->has('medicamentos', 2)
            // Sin plantilla propia, los dos arrancan con el genérico, con sus variables.
            ->where('medicamentos.0.plantilla', PlantillaDePedido::ORIGINAL)
            ->where('medicamentos.0.plantillaPropia', false)
            ->where('medicamentos.1.plantilla', PlantillaDePedido::ORIGINAL)
            ->has('destinatarios', 1));

    $this->actingAs($usuario)
        ->post(route('pedidos.store'), [
            'paciente_id' => $paciente->id,
            'pedidos' => [
                ['tratamiento_id' => $tratamiento->id, 'mensaje' => 'Dos cajas, como siempre'],
                ['tratamiento_id' => $otro->id, 'mensaje' => 'Una caja de 30'],
            ],
            'destinatario' => "medico:{$medico->id}",
        ])
        ->assertSessionHasNoErrors();

    Mail::assertSent(PedidoDeMedicamentos::class, fn (PedidoDeMedicamentos $mail): bool => str_contains(
        $mail->render(),
        "- Losartán · 50 mg\nDos cajas, como siempre\n\n- Atorvastatina · 20 mg\nUna caja de 30",
    ));

    expect(DB::table('tratamientos')->where('id', $tratamiento->id)->value('plantilla_pedido'))->not->toContain('cajas');

    $this->actingAs($usuario)
        ->get(route('pedidos.index', ['paciente' => $paciente->id]))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('medicamentos.0.plantilla', 'Una caja de 30')
            ->where('medicamentos.1.plantilla', 'Dos cajas, como siempre'));
});

it('pide un mensaje para cada medicamento y no deja repetir uno', function (): void {
    Mail::fake();
    [$usuario, $paciente, $tratamiento, $medico] = fichaParaPedido();

    $this->actingAs($usuario)
        ->post(route('pedidos.store'), [
            'paciente_id' => $paciente->id,
            'pedidos' => [
                ['tratamiento_id' => $tratamiento->id, 'mensaje' => ''],
                ['tratamiento_id' => $tratamiento->id, 'mensaje' => 'Hola'],
            ],
            'destinatario' => "medico:{$medico->id}",
        ])
        ->assertSessionHasErrors(['pedidos.0.mensaje', 'pedidos.1.tratamiento_id']);

    $this->actingAs($usuario)
        ->post(route('pedidos.store'), [
            'paciente_id' => $paciente->id,
            'destinatario' => "medico:{$medico->id}",
        ])
        ->assertSessionHasErrors(['pedidos' => 'Elegí al menos un medicamento.']);

    Mail::assertNothingSent();
});

it('no acepta un medicamento de otra ficha, un médico ajeno ni un contacto de la libreta', function (): void {
    Mail::fake();
    [$usuario, $paciente] = fichaParaPedido();
    [, , $tratamientoAjeno, $medicoAjeno] = fichaParaPedido();
    $contacto = Contacto::factory()->for($usuario, 'usuario')->create();

    foreach (["medico:{$medicoAjeno->id}", "contacto:{$contacto->id}"] as $clave) {
        $this->actingAs($usuario)
            ->post(route('pedidos.store'), [
                'paciente_id' => $paciente->id,
                'pedidos' => [['tratamiento_id' => $tratamientoAjeno->id, 'mensaje' => 'Hola']],
                'destinatario' => $clave,
            ])
            ->assertSessionHasErrors(['pedidos.0.tratamiento_id', 'destinatario']);
    }

    Mail::assertNothingSent();
    expect(Envio::count())->toBe(0);
});

it('un lector no puede mandar pedidos de la ficha', function (): void {
    Mail::fake();
    [, $paciente, $tratamiento] = fichaParaPedido();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);
    $medico = Medico::factory()->for($lector, 'usuario')->create(['email' => 'otro@example.com']);

    $this->actingAs($lector)
        ->post(route('pedidos.store'), [
            'paciente_id' => $paciente->id,
            'pedidos' => [['tratamiento_id' => $tratamiento->id, 'mensaje' => 'Hola']],
            'destinatario' => "medico:{$medico->id}",
        ])
        ->assertForbidden();

    Mail::assertNothingSent();
});

it('si el mail falla, lo anota como fallido y avisa', function (): void {
    [$usuario, $paciente, $tratamiento, $medico] = fichaParaPedido();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));

    $this->actingAs($usuario)
        ->post(route('pedidos.store'), [
            'paciente_id' => $paciente->id,
            'pedidos' => [['tratamiento_id' => $tratamiento->id, 'mensaje' => 'Hola']],
            'destinatario' => "medico:{$medico->id}",
        ])
        ->assertSessionHas('error');

    expect(Envio::sole()->estado)->toBe(EstadoEnvio::Fallido);
});

it('«Guardar plantilla» deja el mensaje cifrado como el de por defecto, sin mandar nada', function (): void {
    Mail::fake();
    [$usuario, $paciente, $tratamiento] = fichaParaPedido();

    $this->actingAs($usuario)
        ->put(route('pedidos.plantilla', $tratamiento), ['mensaje' => '  Dos cajas de 30, como siempre.  '])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('exito', 'Se guardó el mensaje de Losartán.');

    expect($tratamiento->fresh()?->plantilla_pedido)->toBe('Dos cajas de 30, como siempre.')
        ->and(DB::table('tratamientos')->where('id', $tratamiento->id)->value('plantilla_pedido'))->not->toContain('cajas')
        ->and(Envio::count())->toBe(0);
    Mail::assertNothingSent();

    $this->actingAs($usuario)
        ->get(route('pedidos.index', ['paciente' => $paciente->id]))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('medicamentos.0.plantilla', 'Dos cajas de 30, como siempre.'));
});

it('«Guardar plantilla» pide el mensaje', function (): void {
    [$usuario, , $tratamiento] = fichaParaPedido();

    $this->actingAs($usuario)
        ->put(route('pedidos.plantilla', $tratamiento), ['mensaje' => '   '])
        ->assertSessionHasErrors(['mensaje' => 'Escribí el mensaje que querés guardar.']);

    expect($tratamiento->fresh()?->plantilla_pedido)->toBeNull();
});

it('un lector y un extraño no guardan la plantilla de un medicamento', function (): void {
    [, $paciente, $tratamiento] = fichaParaPedido();
    $lector = User::factory()->create();
    $paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    foreach ([$lector, User::factory()->create()] as $quien) {
        $this->actingAs($quien)
            ->put(route('pedidos.plantilla', $tratamiento), ['mensaje' => 'Otra cosa'])
            ->assertForbidden();
    }

    expect($tratamiento->fresh()?->plantilla_pedido)->toBeNull();
});

// ---------------------------------------------------------------------------
// El mensaje genérico de la cuenta y las variables
// ---------------------------------------------------------------------------

it('el mensaje genérico es de la cuenta, cifrado, y lo usan los medicamentos sin plantilla propia', function (): void {
    [$usuario, $paciente, $losartan] = fichaParaPedido();
    $otro = Tratamiento::factory()->create([
        'paciente_id' => $paciente->id,
        'medicamento_id' => Medicamento::factory()->for($usuario, 'usuario')->create(['nombre_comercial' => 'Atorvastatina'])->id,
    ]);
    $losartan->forceFill(['plantilla_pedido' => 'El mío para Losartán'])->save();

    $this->actingAs($usuario)
        ->put(route('pedidos.plantilla-generica'), ['mensaje' => '  Buen día, necesito {medicamento} para {nombre}.  '])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('exito', 'Se guardó el mensaje genérico.');

    expect($usuario->fresh()?->plantilla_pedido)->toBe('Buen día, necesito {medicamento} para {nombre}.')
        ->and(DB::table('users')->where('id', $usuario->id)->value('plantilla_pedido'))->not->toContain('necesito');

    $this->actingAs($usuario)
        ->get(route('pedidos.index', ['paciente' => $paciente->id, 'solapa' => 'mensajes']))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('plantillaGenerica', 'Buen día, necesito {medicamento} para {nombre}.')
            ->where('plantillaOriginal', PlantillaDePedido::ORIGINAL)
            ->where('paciente.variables', ['nombre' => 'Marta', 'apellido' => ''])
            ->where('medicamentos.0.nombre', 'Atorvastatina')
            ->where('medicamentos.0.plantilla', 'Buen día, necesito {medicamento} para {nombre}.')
            ->where('medicamentos.0.plantillaPropia', false)
            ->where('medicamentos.1.plantilla', 'El mío para Losartán')
            ->where('medicamentos.1.plantillaPropia', true));

    expect($otro->fresh()?->plantilla_pedido)->toBeNull();
});

it('guardar el original es volver a no tener un genérico propio', function (): void {
    [$usuario] = fichaParaPedido();
    $usuario->forceFill(['plantilla_pedido' => 'Otro'])->save();

    $this->actingAs($usuario)
        ->put(route('pedidos.plantilla-generica'), ['mensaje' => PlantillaDePedido::ORIGINAL])
        ->assertSessionHasNoErrors();

    expect($usuario->fresh()?->plantilla_pedido)->toBeNull();
});

it('el mensaje genérico no puede quedar vacío', function (): void {
    [$usuario] = fichaParaPedido();

    $this->actingAs($usuario)
        ->put(route('pedidos.plantilla-generica'), ['mensaje' => ''])
        ->assertSessionHasErrors(['mensaje' => 'Escribí el mensaje genérico, o tocá «Volver al original».']);
});

it('el mensaje genérico no viaja en los props de cada página', function (): void {
    [$usuario] = fichaParaPedido();
    $usuario->forceFill(['plantilla_pedido' => 'Mensaje que no tiene que salir'])->save();

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $pagina) => $pagina->missing('auth.user.plantilla_pedido'));
});

it('al mandar reemplaza las variables, sin importar mayúsculas, y guarda la plantilla con ellas', function (): void {
    Mail::fake();
    [$usuario, $paciente, $tratamiento, $medico] = fichaParaPedido();
    $paciente->update(['nombre' => 'Marta  Gómez Ruiz']);

    $this->actingAs($usuario)
        ->post(route('pedidos.store'), [
            'paciente_id' => $paciente->id,
            'pedidos' => [['tratamiento_id' => $tratamiento->id, 'mensaje' => 'Receta de {Medicamento} para {nombre} {APELLIDO}, por favor.']],
            'destinatario' => "medico:{$medico->id}",
        ])
        ->assertSessionHasNoErrors();

    Mail::assertSent(PedidoDeMedicamentos::class, fn (PedidoDeMedicamentos $mail): bool => str_contains(
        $mail->render(),
        "- Losartán · 50 mg\nReceta de Losartán para Marta Gómez Ruiz, por favor.",
    ));

    // La plantilla queda con las variables: sirve aunque cambie el medicamento.
    expect($tratamiento->fresh()?->plantilla_pedido)->toBe('Receta de {Medicamento} para {nombre} {APELLIDO}, por favor.');
});

it('mandar el genérico tal cual no lo congela como plantilla propia', function (): void {
    Mail::fake();
    [$usuario, $paciente, $tratamiento, $medico] = fichaParaPedido();

    $this->actingAs($usuario)
        ->post(route('pedidos.store'), [
            'paciente_id' => $paciente->id,
            'pedidos' => [['tratamiento_id' => $tratamiento->id, 'mensaje' => PlantillaDePedido::ORIGINAL]],
            'destinatario' => "medico:{$medico->id}",
        ])
        ->assertSessionHasNoErrors();

    // Sin apellido no queda un espacio de más antes del signo.
    Mail::assertSent(PedidoDeMedicamentos::class, fn (PedidoDeMedicamentos $mail): bool => str_contains(
        $mail->render(),
        'Hola, ¿me podrías hacer la receta de Losartán para Marta? Gracias.',
    ));
    expect($tratamiento->fresh()?->plantilla_pedido)->toBeNull();
});

it('parte el nombre de la ficha en nombre y apellido', function (string $completo, string $nombre, string $apellido): void {
    expect(PlantillaDePedido::partesDelNombre($completo))->toBe(['nombre' => $nombre, 'apellido' => $apellido]);
})->with([
    'nombre y apellido' => ['Marta Gómez', 'Marta', 'Gómez'],
    'dos apellidos' => ['Roberto Pérez Díaz', 'Roberto', 'Pérez Díaz'],
    'solo nombre' => ['Tomás', 'Tomás', ''],
    'espacios de más' => ['  Ana   López ', 'Ana', 'López'],
]);
