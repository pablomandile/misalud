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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Pedido de medicamentos: por mail, con la plantilla de la ficha
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
            'tratamientos' => [$tratamiento->id],
            'destinatario' => "medico:{$medico->id}",
            'mensaje' => 'Hola, necesito la receta para Marta.',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('exito', 'Se mandó el pedido a Dra. López.');

    Mail::assertSent(PedidoDeMedicamentos::class, function (PedidoDeMedicamentos $mail) use ($usuario): bool {
        $texto = $mail->render();

        return $mail->hasTo('lopez@example.com')
            && $mail->hasReplyTo($usuario->email)
            && $mail->hasSubject('Pedido de medicamentos')
            && str_contains($texto, 'Hola, necesito la receta para Marta.')
            && str_contains($texto, '- Losartán · 50 mg');
    });

    $envio = Envio::sole();
    expect($envio->estado)->toBe(EstadoEnvio::Enviado)
        ->and($envio->destinatario)->toBe('lopez@example.com')
        ->and($envio->cuerpo)->toContain('- Losartán · 50 mg');
});

it('guarda el mensaje como plantilla cifrada de la ficha y lo precarga la próxima vez', function (): void {
    Mail::fake();
    [$usuario, $paciente, $tratamiento, $medico] = fichaParaPedido();

    $this->actingAs($usuario)
        ->get(route('pedidos.index', ['paciente' => $paciente->id]))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('pedidos/Index')
            ->where('plantilla', 'Hola, ¿me podrías hacer la receta de estos medicamentos para Marta? Gracias.')
            ->has('medicamentos', 1)
            ->has('destinatarios', 1));

    $this->actingAs($usuario)->post(route('pedidos.store'), [
        'paciente_id' => $paciente->id,
        'tratamientos' => [$tratamiento->id],
        'destinatario' => "medico:{$medico->id}",
        'mensaje' => 'Mi texto de siempre',
    ]);

    expect(DB::table('pacientes')->where('id', $paciente->id)->value('plantilla_pedido'))->not->toContain('siempre');

    $this->actingAs($usuario)
        ->get(route('pedidos.index', ['paciente' => $paciente->id]))
        ->assertInertia(fn (Assert $pagina) => $pagina->where('plantilla', 'Mi texto de siempre'));
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
                'tratamientos' => [$tratamientoAjeno->id],
                'destinatario' => $clave,
                'mensaje' => 'Hola',
            ])
            ->assertSessionHasErrors(['tratamientos.0', 'destinatario']);
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
            'tratamientos' => [$tratamiento->id],
            'destinatario' => "medico:{$medico->id}",
            'mensaje' => 'Hola',
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
            'tratamientos' => [$tratamiento->id],
            'destinatario' => "medico:{$medico->id}",
            'mensaje' => 'Hola',
        ])
        ->assertSessionHas('error');

    expect(Envio::sole()->estado)->toBe(EstadoEnvio::Fallido);
});
