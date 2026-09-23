<?php

declare(strict_types=1);

use App\Enums\TamanioTexto;
use App\Models\User;

it('viene en Grande por defecto, no en el tamaño chico', function (): void {
    expect(TamanioTexto::porDefecto())->toBe(TamanioTexto::Grande)
        ->and(User::factory()->create()->tamanio_texto)->toBe(TamanioTexto::Grande);
});

/*
 * En porcentaje y no en px: un tamaño en px pisa el que la persona ya configuró
 * en su navegador, que es justo lo que alguien con poca vista suele haber
 * tocado. Si esto cambia a px, se rompe esa composición sin que se note.
 */
it('expresa los tamaños en porcentaje', function (): void {
    foreach (TamanioTexto::cases() as $caso) {
        expect($caso->porcentaje())->toEndWith('%');
    }

    expect(TamanioTexto::Normal->porcentaje())->toBe('100%');
});

it('escribe el atributo en el html, sin depender de JavaScript', function (): void {
    $html = $this->get('/login')->getContent();

    expect($html)->toContain('data-texto="grande"');
});

it('un invitado puede agrandar la letra y se recuerda por la cookie', function (): void {
    $respuesta = $this->put(route('tamanio-texto.update'), [
        'tamanio_texto' => TamanioTexto::MuyGrande->value,
    ]);

    $respuesta->assertRedirect()
        ->assertCookie('tamanio_texto', TamanioTexto::MuyGrande->value, encrypted: false);

    expect($this->withUnencryptedCookie('tamanio_texto', 'muy-grande')->get('/login')->getContent())
        ->toContain('data-texto="muy-grande"');
});

it('a quien tiene sesión se lo guarda en la cuenta', function (): void {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->put(route('tamanio-texto.update'), ['tamanio_texto' => 'normal'])
        ->assertRedirect();

    expect($usuario->refresh()->tamanio_texto)->toBe(TamanioTexto::Normal);
});

/*
 * El caso que motiva la columna: la persona entra desde un teléfono nuevo, sin
 * la cookie. Si mandara la cookie, tendría que volver a agrandar la letra en
 * cada dispositivo.
 */
it('manda la cuenta por encima de la cookie', function (): void {
    $usuario = User::factory()->create(['tamanio_texto' => TamanioTexto::MuyGrande]);

    $html = $this->actingAs($usuario)
        ->withUnencryptedCookie('tamanio_texto', 'normal')
        ->get('/dashboard')
        ->getContent();

    expect($html)->toContain('data-texto="muy-grande"');
});

it('rechaza un tamaño que no existe', function (): void {
    $this->put(route('tamanio-texto.update'), ['tamanio_texto' => 'gigante'])
        ->assertSessionHasErrors('tamanio_texto');
});

/*
 * La cookie la escribe cualquiera; un valor raro tiene que caer en el default y
 * no dejar la página sin tamaño.
 */
it('una cookie con basura cae en el tamaño por defecto', function (): void {
    expect($this->withUnencryptedCookie('tamanio_texto', 'jajaja')->get('/login')->getContent())
        ->toContain('data-texto="grande"');
});

it('avisa por flash, con un identificador distinto en cada mensaje', function (): void {
    $usuario = User::factory()->create();

    $primero = $this->actingAs($usuario)
        ->put(route('tamanio-texto.update'), ['tamanio_texto' => 'normal']);

    expect($primero->getSession()->get('exito'))->toContain('tamaño de la letra');
});
