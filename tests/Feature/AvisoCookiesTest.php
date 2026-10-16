<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

/*
 * El aviso de cookies es informativo porque todo lo que MiSalud guarda en el
 * navegador es necesario. Esa afirmación solo es cierta mientras la página de
 * cookies liste todo: una clave nueva en localStorage que nadie declara la
 * vuelve falsa sin ningún síntoma. Este test busca en el código cada lugar
 * donde se escribe en el navegador y exige que su clave aparezca en la página.
 */

function fuenteCookies(string $ruta): string
{
    return (string) file_get_contents(base_path($ruta));
}

/**
 * Cada nombre que el código usa para guardar algo en el navegador. Las claves
 * armadas con un template literal se toman hasta el primer `${`, y los
 * nombres de caché del service worker sin su número de versión.
 *
 * @return array<string, string> clave => archivo donde se escribe
 */
function clavesGuardadasEnElNavegador(): array
{
    $claves = [];

    $archivos = collect(File::allFiles(resource_path('js')))
        ->filter(fn ($f) => in_array($f->getExtension(), ['ts', 'vue'], true))
        // Las rutas generadas por Wayfinder no guardan nada.
        ->reject(fn ($f) => preg_match('#[\\\\/](routes|actions|wayfinder)[\\\\/]#', $f->getPathname()));

    foreach ($archivos as $f) {
        $codigo = $f->getContents();
        $nombre = $f->getRelativePathname();

        // Constantes del archivo, para resolver `setItem(CLAVE, …)`.
        preg_match_all('/const (\w+)\s*=\s*[\'"`]([^\'"`$]+)/', $codigo, $m, PREG_SET_ORDER);
        $constantes = collect($m)->mapWithKeys(fn ($c) => [$c[1] => $c[2]]);

        preg_match_all('/(?:local|session)Storage\.setItem\(\s*(?:[\'"`]([^\'"`$]+)|(\w+))/', $codigo, $m, PREG_SET_ORDER);
        foreach ($m as $uso) {
            $variable = $uso[2] ?? '';
            $clave = $uso[1] !== '' ? $uso[1] : $constantes->get($variable);
            expect($clave)->not->toBeNull("No se pudo resolver la clave {$variable} en {$nombre}");
            $claves[$clave] = $nombre;
        }

        // Cookies que escribe el front (el tema y la barra lateral) y bases locales.
        preg_match_all('/setCookie\(\s*[\'"](\w+)[\'"]/', $codigo, $m);
        preg_match_all('/_COOKIE_NAME\s*=\s*[\'"](\w+)[\'"]/', $codigo, $m2);
        preg_match_all('/openDB\(\s*[\'"]([^\'"]+)[\'"]/', $codigo, $m3);
        foreach ([...$m[1], ...$m2[1], ...$m3[1]] as $clave) {
            $claves[$clave] = $nombre;
        }
    }

    preg_match_all("/const \w+ = '([a-z-]+?)-v\d+';/", fuenteCookies('public/sw.js'), $m);
    foreach ($m[1] as $cache) {
        $claves["{$cache}-v"] = 'public/sw.js';
    }

    return $claves;
}

it('la página de cookies declara todo lo que el código guarda en el navegador', function () {
    $claves = clavesGuardadasEnElNavegador();

    // Que la búsqueda encuentre lo que ya se sabe que existe: si un cambio de
    // estilo la dejara sin encontrar nada, el test pasaría sin probar nada.
    expect(array_keys($claves))->toContain(
        'misalud:aviso-cookies',
        'appearance',
        'sidebar_state',
        'misalud-v',
        'misalud-credenciales-v',
    );

    $pagina = fuenteCookies('resources/js/pages/Cookies.vue');

    foreach ($claves as $clave => $archivo) {
        // `toContain` es variádico: el mensaje iría como otra aguja.
        expect(str_contains($pagina, $clave))->toBeTrue("«{$clave}» se guarda en {$archivo} y no figura en la página de cookies");
    }
});

it('la página declara las cookies del servidor', function () {
    $pagina = fuenteCookies('resources/js/pages/Cookies.vue');

    expect($pagina)
        ->toContain(config('session.cookie'))
        ->toContain('XSRF-TOKEN')
        ->toContain('remember_web_')
        // La pone el servidor (TamanioTextoController), no el front.
        ->toContain('tamanio_texto');
});

it('salir de la cuenta borra la misma caché de credenciales que llena el service worker', function () {
    // La página la borra por nombre sin pasar por el service worker: si los
    // dos nombres se separan, salir deja las credenciales guardadas en el
    // dispositivo, sin ningún error.
    preg_match("/const CACHE_CREDENCIALES = '([^']+)';/", fuenteCookies('public/sw.js'), $enElWorker);
    preg_match("/const CACHE_CREDENCIALES = '([^']+)';/", fuenteCookies('resources/js/lib/cacheCredencial.ts'), $enLaPagina);

    expect($enElWorker[1] ?? null)->not->toBeNull()
        ->and($enLaPagina[1] ?? null)->toBe($enElWorker[1]);

    // Y lo llaman todas las salidas de la cuenta.
    foreach ([
        'resources/js/components/UserMenuContent.vue',
        'resources/js/pages/auth/VerifyEmail.vue',
        'resources/js/pages/invitaciones/Aceptar.vue',
        'resources/js/pages/terminos/Aceptar.vue',
    ] as $archivo) {
        expect(str_contains(fuenteCookies($archivo), 'olvidarTodasLasCredenciales'))
            ->toBeTrue("{$archivo} sale de la cuenta sin borrar las credenciales guardadas");
    }
});

it('el aviso está en todas las puertas de entrada', function () {
    // La portada y la página de cookies sin sesión, el ingreso y el registro,
    // y la app: el aviso tiene que verse en la primera visita.
    expect(fuenteCookies('resources/js/layouts/PublicoLayout.vue'))->toContain('<AvisoCookies')
        ->and(fuenteCookies('resources/js/layouts/AuthLayout.vue'))->toContain('<AvisoCookies')
        ->and(fuenteCookies('resources/js/layouts/app/AppSidebarLayout.vue'))->toContain('<AvisoCookies');

    $appTs = fuenteCookies('resources/js/app.ts');
    expect($appTs)->toContain("name === 'Welcome':\n                return PublicoLayout;")
        ->and($appTs)->toContain(': PublicoLayout;');
});

it('se abre sin cuenta', function () {
    $this->get(route('cookies'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cookies')
            ->where('auth.user', null)
        );
});

it('se abre con sesión', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('cookies'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Cookies'));
});
