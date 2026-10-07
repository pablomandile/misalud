<?php

declare(strict_types=1);

use App\Mail\EnvioDeDocumentos;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Los mails de la app son de TEXTO PLANO: sin `{{ }}` de Blade
|--------------------------------------------------------------------------
|
| `{{ }}` escapa como HTML, y en un mail de texto no hay HTML que proteger: lo
| único que logra es romper. Con eso, el enlace de las invitaciones salía con
| "&amp;" en vez de "&" -firma inválida, invitación inservible- y un nombre como
| "D'Angelo" llegaba escrito "D&#039;Angelo". Lo encontró la verificación en
| Chrome leyendo el mail real. Esta guardia hace que una vista nueva no lo repita.
|
*/

it('ninguna vista de mail usa {{ }}: en texto plano el escape HTML solo rompe', function (): void {
    $culpables = [];

    foreach (glob(resource_path('views/mail/*.blade.php')) ?: [] as $vista) {
        // Los comentarios de Blade `{{-- --}}` no imprimen nada: no cuentan.
        $sinComentarios = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($vista));

        if (str_contains((string) $sinComentarios, '{{')) {
            $culpables[] = basename($vista);
        }
    }

    expect($culpables)->toBe([], 'Estas vistas de mail usan {{ }}: en texto plano va {!! !!}. '
        .implode(', ', $culpables));
});

it('un nombre con apóstrofo llega escrito tal cual', function (): void {
    $usuario = User::factory()->make(['name' => "María D'Angelo & Cía"]);

    $cuerpo = (new EnvioDeDocumentos($usuario, 'Asunto', 'Les mando "la orden".', []))->render();

    expect($cuerpo)->toContain("María D'Angelo & Cía te manda")
        ->and($cuerpo)->toContain('Les mando "la orden".')
        ->and($cuerpo)->not->toContain('&#039;')
        ->and($cuerpo)->not->toContain('&amp;');
});
