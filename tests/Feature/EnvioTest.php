<?php

declare(strict_types=1);

use App\Contracts\TieneArchivos;
use App\Enums\EstadoEnvio;
use App\Enums\RolPaciente;
use App\Enums\TipoAdjunto;
use App\Mail\EnvioDeDocumentos;
use App\Models\Adjunto;
use App\Models\Cobertura;
use App\Models\Contacto;
use App\Models\Envio;
use App\Models\OrdenEstudio;
use App\Models\Paciente;
use App\Models\Receta;
use App\Models\User;
use App\Services\ArchivoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Mail::fake();

    $this->usuario = User::factory()->create(['name' => 'Ana Gómez', 'email' => 'ana@misalud.test']);
    $this->paciente = Paciente::factory()->for($this->usuario, 'usuario')->create(['nombre' => 'Rosa Gómez']);
    $this->contacto = Contacto::factory()->for($this->usuario, 'usuario')->create([
        'nombre' => 'OSDE Autorizaciones',
        'email' => 'autorizaciones@osde.test',
    ]);
});

/** Un PDF que finfo reconoce como tal, con un texto para distinguirlo. */
function pdfParaEnviar(string $texto): string
{
    return "%PDF-1.4\n1 0 obj\n<</Type/Catalog>>\nendobj\ntrailer<</Root 1 0 R>>\n%%EOF\n{$texto}";
}

/**
 * Un archivo de verdad -cifrado en el disco falso- colgado de su dueño, por el
 * mismo camino que usa la app.
 */
function archivoPara(Model&TieneArchivos $duenio, string $nombre, TipoAdjunto $tipo, string $contenido): Adjunto
{
    $datos = app(ArchivoService::class)->guardarContenido($contenido, $nombre, $duenio->carpetaDeArchivos());

    return $duenio->adjuntos()->create([...$datos, 'tipo' => $tipo]);
}

/**
 * La orden de una ficha, con su papel adjunto.
 *
 * @return array{0: OrdenEstudio, 1: Adjunto}
 */
function ordenConPapel(Paciente $paciente, string $estudio = 'Ecografía'): array
{
    $orden = OrdenEstudio::factory()->for($paciente)->create(['estudio_solicitado' => $estudio]);

    return [$orden, archivoPara($orden, 'orden.pdf', TipoAdjunto::OrdenEstudio, pdfParaEnviar("orden {$estudio}"))];
}

/*
|--------------------------------------------------------------------------
| Armar el envío
|--------------------------------------------------------------------------
*/

it('arma el envío desde un documento y ofrece los de la misma ficha para sumar', function (): void {
    [, $papel] = ordenConPapel($this->paciente);
    $cobertura = Cobertura::factory()->for($this->paciente)->create(['entidad' => 'OSDE']);
    $credencial = archivoPara($cobertura, 'frente.pdf', TipoAdjunto::Credencial, pdfParaEnviar('credencial'));

    $this->actingAs($this->usuario)
        ->get(route('envios.create', ['adjuntos' => [$papel->id]]))
        ->assertInertia(fn ($pagina) => $pagina
            ->component('envios/Nuevo')
            ->has('documentos', 2)
            ->where('pacienteNombre', 'Rosa Gómez')
            ->where('asuntoSugerido', 'Documentación de Rosa Gómez')
            ->where('documentos', fn ($docs): bool => collect($docs)->contains(
                fn ($d): bool => $d['id'] === $papel->id
                    && $d['elegido'] === true
                    && $d['descripcion'] === 'Orden de estudio · Ecografía',
            ) && collect($docs)->contains(
                fn ($d): bool => $d['id'] === $credencial->id
                    && $d['elegido'] === false
                    && $d['descripcion'] === 'Credencial · OSDE',
            )));
});

it('⚠️ no ofrece documentos de OTRO familiar de la misma cuenta', function (): void {
    /*
     * Mezclar fichas en un mismo mail es el error más caro de este módulo: la
     * receta de un familiar terminando en la obra social de otro.
     */
    [, $papel] = ordenConPapel($this->paciente);
    $otroFamiliar = Paciente::factory()->for($this->usuario, 'usuario')->create();
    [, $papelAjenoAFicha] = ordenConPapel($otroFamiliar, 'Tomografía');

    $this->actingAs($this->usuario)
        ->get(route('envios.create', ['adjuntos' => [$papel->id]]))
        ->assertInertia(fn ($pagina) => $pagina
            ->has('documentos', 1)
            ->where('documentos.0.id', $papel->id));

    expect($papelAjenoAFicha->id)->not->toBe($papel->id);
});

it('desde una receta importada ofrece las otras recetas de la casilla', function (): void {
    $receta = Receta::factory()->create(['usuario_id' => $this->usuario->id, 'asunto' => 'Receta de octubre']);
    $otra = Receta::factory()->create(['usuario_id' => $this->usuario->id, 'asunto' => 'Receta de septiembre']);
    $a = archivoPara($receta, 'r1.pdf', TipoAdjunto::Receta, pdfParaEnviar('r1'));
    archivoPara($otra, 'r2.pdf', TipoAdjunto::Receta, pdfParaEnviar('r2'));

    $this->actingAs($this->usuario)
        ->get(route('envios.create', ['adjuntos' => [$a->id]]))
        ->assertInertia(fn ($pagina) => $pagina
            ->has('documentos', 2)
            ->where('asuntoSugerido', 'Receta')
            ->where('pacienteNombre', null));
});

it('sin documentos elegidos, manda a la libreta con una explicación', function (): void {
    $this->actingAs($this->usuario)
        ->get(route('envios.create'))
        ->assertRedirect(route('contactos.index'))
        ->assertSessionHas('error');
});

it('⚠️ armar un envío con un documento ajeno da 403', function (): void {
    [, $ajeno] = ordenConPapel(Paciente::factory()->create());

    $this->actingAs($this->usuario)
        ->get(route('envios.create', ['adjuntos' => [$ajeno->id]]))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Mandar
|--------------------------------------------------------------------------
*/

it('manda el mail al contacto, con los archivos y la respuesta yendo a la persona', function (): void {
    [, $papel] = ordenConPapel($this->paciente);
    $cobertura = Cobertura::factory()->for($this->paciente)->create(['entidad' => 'OSDE']);
    $credencial = archivoPara($cobertura, 'frente.pdf', TipoAdjunto::Credencial, pdfParaEnviar('credencial'));

    $this->actingAs($this->usuario)
        ->post(route('envios.store'), [
            'contacto_id' => $this->contacto->id,
            'adjuntos' => [$papel->id, $credencial->id],
            'asunto' => 'Documentación de Rosa Gómez',
            'mensaje' => 'Les mando la orden para autorizar.',
        ])
        ->assertRedirect(route('contactos.index'))
        ->assertSessionHas('exito', 'Se mandó a OSDE Autorizaciones.');

    Mail::assertSent(EnvioDeDocumentos::class, function (EnvioDeDocumentos $mail): bool {
        $mail->assertHasTo('autorizaciones@osde.test');
        // Si la obra social contesta, le contesta a Ana, no a la casilla de la app.
        $mail->assertHasReplyTo('ana@misalud.test');
        $mail->assertHasSubject('Documentación de Rosa Gómez');
        // Los archivos van DESCIFRADOS: es lo que el destinatario tiene que poder abrir.
        $mail->assertHasAttachedData(pdfParaEnviar('orden Ecografía'), 'orden.pdf', ['mime' => 'application/pdf']);
        $mail->assertHasAttachedData(pdfParaEnviar('credencial'), 'frente.pdf', ['mime' => 'application/pdf']);

        return true;
    });

    $envio = Envio::sole();

    expect($envio->usuario_id)->toBe($this->usuario->id)
        ->and($envio->estado)->toBe(EstadoEnvio::Enviado)
        ->and($envio->destinatario)->toBe('autorizaciones@osde.test')
        ->and($envio->destinatario_nombre)->toBe('OSDE Autorizaciones')
        ->and($envio->cuerpo)->toBe('Les mando la orden para autorizar.')
        ->and($envio->archivos()->pluck('adjunto_id')->all())->toBe([$papel->id, $credencial->id]);
});

it('⚠️ mandar un documento AJENO da 403, y no sale nada ni queda nada', function (): void {
    /*
     * El punto donde este módulo podía filtrar datos de otra persona: un id ajeno
     * puesto a mano en el formulario, mandado a la propia casilla. La lista que
     * mostró la pantalla no es una autorización.
     */
    [, $propio] = ordenConPapel($this->paciente);
    [, $ajeno] = ordenConPapel(Paciente::factory()->create(), 'De otra persona');

    $this->actingAs($this->usuario)
        ->post(route('envios.store'), [
            'contacto_id' => $this->contacto->id,
            'adjuntos' => [$propio->id, $ajeno->id],
            'asunto' => 'Algo',
        ])
        ->assertForbidden();

    Mail::assertNothingSent();
    expect(Envio::count())->toBe(0);
});

it('⚠️ autoriza ANTES de validar: un id ajeno con otro campo inválido da 403', function (): void {
    // Si validara primero, contestaría con un error de campo y confirmaría que el
    // archivo existe.
    [, $ajeno] = ordenConPapel(Paciente::factory()->create());

    $this->actingAs($this->usuario)
        ->post(route('envios.store'), [
            'contacto_id' => $this->contacto->id,
            'adjuntos' => [$ajeno->id],
            'asunto' => '',
        ])
        ->assertForbidden();
});

it('⚠️ el destinatario tiene que ser de la libreta propia', function (): void {
    [, $papel] = ordenConPapel($this->paciente);
    $ajeno = Contacto::factory()->create();

    $this->actingAs($this->usuario)
        ->post(route('envios.store'), [
            'contacto_id' => $ajeno->id,
            'adjuntos' => [$papel->id],
            'asunto' => 'Algo',
        ])
        ->assertSessionHasErrors('contacto_id');

    Mail::assertNothingSent();
});

it('⚠️ no se puede mandar a una dirección suelta, solo a un contacto', function (): void {
    // Un tipeo en una dirección escrita en el momento manda una historia clínica
    // a un desconocido.
    [, $papel] = ordenConPapel($this->paciente);

    $this->actingAs($this->usuario)
        ->post(route('envios.store'), [
            'email' => 'cualquiera@otro.test',
            'adjuntos' => [$papel->id],
            'asunto' => 'Algo',
        ])
        ->assertSessionHasErrors('contacto_id');

    Mail::assertNothingSent();
});

it('frena un envío que pasa del tope de tamaño, antes de mandar', function (): void {
    [, $papel] = ordenConPapel($this->paciente);
    // 16 MB declarados: el tope es 15, por el base64 del mail.
    $papel->forceFill(['tamanio_bytes' => 16 * 1024 * 1024])->save();

    $this->actingAs($this->usuario)
        ->post(route('envios.store'), [
            'contacto_id' => $this->contacto->id,
            'adjuntos' => [$papel->id],
            'asunto' => 'Algo',
        ])
        ->assertSessionHasErrors('adjuntos');

    Mail::assertNothingSent();
});

it('el asunto va en una sola línea: es una cabecera del mail', function (): void {
    [, $papel] = ordenConPapel($this->paciente);

    $this->actingAs($this->usuario)
        ->post(route('envios.store'), [
            'contacto_id' => $this->contacto->id,
            'adjuntos' => [$papel->id],
            'asunto' => "Hola\r\nBcc: todos@spam.test",
        ])
        ->assertSessionHasErrors('asunto');

    Mail::assertNothingSent();
});

it('⚠️ un documento BORRADO no se manda: no sale un mail vacío', function (): void {
    /*
     * `exists` va contra la tabla cruda y no sabe de soft deletes: el id de un
     * documento en la papelera pasaba la validación, no se cargaba, y salía un mail
     * sin ningún adjunto.
     */
    [, $papel] = ordenConPapel($this->paciente);
    $papel->delete();

    $this->actingAs($this->usuario)
        ->post(route('envios.store'), [
            'contacto_id' => $this->contacto->id,
            'adjuntos' => [$papel->id],
            'asunto' => 'Algo',
        ])
        ->assertSessionHasErrors('adjuntos.0');

    Mail::assertNothingSent();
    expect(Envio::count())->toBe(0);
});

it('un documento repetido no se manda dos veces', function (): void {
    [, $papel] = ordenConPapel($this->paciente);

    $this->actingAs($this->usuario)
        ->post(route('envios.store'), [
            'contacto_id' => $this->contacto->id,
            'adjuntos' => [$papel->id, $papel->id],
            'asunto' => 'Algo',
        ])
        ->assertSessionHasErrors('adjuntos.0');
});

it('si falla el envío, queda registrado como no enviado y avisa', function (): void {
    /*
     * Un archivo que no se puede leer hace caer el envío dentro del servicio, por
     * el mismo camino que un servidor de correo caído.
     */
    [, $papel] = ordenConPapel($this->paciente);
    Storage::disk('local')->delete($papel->ruta);

    $this->actingAs($this->usuario)
        ->from(route('envios.create', ['adjuntos' => [$papel->id]]))
        ->post(route('envios.store'), [
            'contacto_id' => $this->contacto->id,
            'adjuntos' => [$papel->id],
            'asunto' => 'Algo',
        ])
        ->assertSessionHas('error');

    Mail::assertNothingSent();
    expect(Envio::sole()->estado)->toBe(EstadoEnvio::Fallido)
        ->and(Envio::sole()->archivos()->count())->toBe(1);
});

it('un lector de la ficha puede mandar lo que puede ver', function (): void {
    /*
     * Mandar no pide más que ver: quien puede abrir el PDF ya lo puede reenviar
     * desde su propio correo. Se revisa en la Etapa 14, con los roles.
     */
    $lector = User::factory()->create();
    $this->paciente->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);
    $suContacto = Contacto::factory()->for($lector, 'usuario')->create();
    [, $papel] = ordenConPapel($this->paciente);

    $this->actingAs($lector)
        ->post(route('envios.store'), [
            'contacto_id' => $suContacto->id,
            'adjuntos' => [$papel->id],
            'asunto' => 'Algo',
        ])
        ->assertSessionHas('exito');

    Mail::assertSent(EnvioDeDocumentos::class, 1);
});

it('⚠️ el mail NO se encola: llevaría los archivos descifrados a la tabla jobs', function (): void {
    $mail = new EnvioDeDocumentos($this->usuario, 'Asunto', null, []);

    expect($mail)->not->toBeInstanceOf(ShouldQueue::class);
});

it('el cuerpo dice quién lo manda y qué archivos van', function (): void {
    $cuerpo = (new EnvioDeDocumentos($this->usuario, 'Asunto', 'Les mando la orden.', [
        ['nombre' => 'orden.pdf', 'mime' => 'application/pdf', 'contenido' => 'x'],
    ]))->render();

    expect($cuerpo)->toContain('Ana Gómez te manda esta documentación')
        ->and($cuerpo)->toContain('Les mando la orden.')
        ->and($cuerpo)->toContain('- orden.pdf')
        ->and($cuerpo)->toContain('la respuesta le llega a Ana Gómez');
});

it('guarda cifrado el historial', function (): void {
    [, $papel] = ordenConPapel($this->paciente);

    $this->actingAs($this->usuario)->post(route('envios.store'), [
        'contacto_id' => $this->contacto->id,
        'adjuntos' => [$papel->id],
        'asunto' => 'Asunto reservado',
    ]);

    $fila = DB::table('envios')->sole();

    expect($fila->destinatario)->not->toContain('osde')
        ->and($fila->asunto)->not->toContain('reservado')
        ->and(DB::table('adjunto_envio')->sole()->nombre)->not->toContain('orden');
});

it('limita la frecuencia de envíos', function (): void {
    [, $papel] = ordenConPapel($this->paciente);
    $datos = [
        'contacto_id' => $this->contacto->id,
        'adjuntos' => [$papel->id],
        'asunto' => 'Algo',
    ];

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($this->usuario)->post(route('envios.store'), $datos);
    }

    $this->actingAs($this->usuario)
        ->post(route('envios.store'), $datos)
        ->assertTooManyRequests();
});
