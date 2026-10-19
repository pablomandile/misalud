<?php

declare(strict_types=1);

use App\Enums\TipoDocumentoPorMail;
use App\Support\AsuntoDeDocumento;
use App\Support\AutenticidadDelMail;
use App\Support\CabecerasDelMail;

/*
|--------------------------------------------------------------------------
| El asunto
|--------------------------------------------------------------------------
*/

it('reconoce el tipo sin importar mayúsculas, tildes ni plurales', function (?string $asunto, ?TipoDocumentoPorMail $tipo): void {
    expect(AsuntoDeDocumento::leer($asunto)->tipo)->toBe($tipo);
})->with([
    'receta' => ['Receta Enalapril', TipoDocumentoPorMail::Receta],
    'RECETAS' => ['RECETAS de marzo', TipoDocumentoPorMail::Receta],
    'órdenes con tilde' => ['Órdenes del cardiólogo', TipoDocumentoPorMail::Orden],
    'orden' => ['orden ecografía', TipoDocumentoPorMail::Orden],
    'estudio' => ['Estudio laboratorio', TipoDocumentoPorMail::Estudio],
    'con dos puntos' => ['Receta: Enalapril', TipoDocumentoPorMail::Receta],
    'reenviado' => ['RV: Fwd: Receta Enalapril', TipoDocumentoPorMail::Receta],
    'palabra entera: recetario no' => ['Recetario de cocina', null],
    'sin palabra clave' => ['Te mando esto', null],
    'vacío' => [null, null],
]);

it('con dos tipos distintos no adivina', function (): void {
    $asunto = AsuntoDeDocumento::leer('Receta y orden de Mamá');

    expect($asunto->tipo)->toBeNull()
        ->and($asunto->ambiguo)->toBeTrue();
});

it('el mismo tipo repetido no es ambiguo', function (): void {
    expect(AsuntoDeDocumento::leer('Receta recetas')->tipo)->toBe(TipoDocumentoPorMail::Receta);
});

it('el resto es el asunto sin la palabra clave, el prefijo ni el nombre', function (): void {
    $asunto = AsuntoDeDocumento::leer('RV: Receta Mamá: Enalapril 10 mg');

    expect($asunto->resto(['mama']))->toBe('Enalapril 10 mg');
});

/*
|--------------------------------------------------------------------------
| Los encabezados
|--------------------------------------------------------------------------
*/

it('lee el Authentication-Results de más arriba, con sus líneas de continuación', function (): void {
    $crudas = "Authentication-Results: mx.hostinger.com;\r\n\tdkim=pass header.d=gmail.com;\r\n\tdmarc=pass header.from=gmail.com\r\n"
        ."Authentication-Results: otro; dmarc=fail\r\nFrom: ana@gmail.com\r\n\r\ncuerpo";

    expect(CabecerasDelMail::autenticacion($crudas))
        ->toBe('mx.hostinger.com; dkim=pass header.d=gmail.com; dmarc=pass header.from=gmail.com');
});

it('reconoce un mail automático', function (string $crudas, string $remitente, bool $automatico): void {
    expect(CabecerasDelMail::esAutomatico($crudas, $remitente))->toBe($automatico);
})->with([
    'auto-submitted' => ["Auto-Submitted: auto-replied\r\n\r\n", 'ana@gmail.com', true],
    'auto-submitted no' => ["Auto-Submitted: no\r\n\r\n", 'ana@gmail.com', false],
    'rebote' => ["Return-Path: <>\r\n\r\n", 'ana@gmail.com', true],
    'mailer-daemon' => ["\r\n\r\n", 'mailer-daemon@googlemail.com', true],
    'precedence bulk' => ["Precedence: bulk\r\n\r\n", 'ana@gmail.com', true],
    'una persona' => ["Subject: Receta\r\n\r\n", 'ana@gmail.com', false],
]);

/*
|--------------------------------------------------------------------------
| La autenticidad
|--------------------------------------------------------------------------
*/

it('decide si el mail es auténtico', function (?string $cabecera, string $remitente, bool $autentico): void {
    expect(AutenticidadDelMail::verificada($cabecera, $remitente))->toBe($autentico);
})->with([
    'dmarc pass' => ['mx; dmarc=pass (p=NONE) header.from=gmail.com', 'ana@gmail.com', true],
    'dmarc pass de otro From' => ['mx; dmarc=pass header.from=otro.com', 'ana@gmail.com', false],
    'dkim alineado' => ['mx; dkim=pass header.d=gmail.com header.s=x', 'ana@gmail.com', true],
    'dkim de un subdominio' => ['mx; dkim=pass header.d=mail.empresa.com', 'ana@empresa.com', true],
    'dkim de otro dominio' => ['mx; dkim=pass header.d=spammer.com', 'ana@gmail.com', false],
    'dominio parecido no' => ['mx; dkim=pass header.d=nogmail.com', 'ana@gmail.com', false],
    'spf alineado' => ['mx; spf=pass smtp.mailfrom=ana@gmail.com', 'ana@gmail.com', true],
    'spf de otro dominio' => ['mx; spf=pass smtp.mailfrom=x@spammer.com', 'ana@gmail.com', false],
    'todo falla' => ['mx; spf=fail smtp.mailfrom=gmail.com; dkim=fail header.d=gmail.com; dmarc=fail header.from=gmail.com', 'ana@gmail.com', false],
    'sin encabezado' => [null, 'ana@gmail.com', false],
    'el servidor como resultado' => ['dmarc=pass', 'ana@gmail.com', false],
]);
