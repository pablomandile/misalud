<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 * Linux distingue mayúsculas y Windows no. Una página renderizada con el case
 * equivocado anda perfecto en desarrollo y rompe en el CI y en producción, con
 * un "Inertia page component file does not exist" que aparece recién al
 * desplegar.
 *
 * Este test lo detecta **desde Windows**, que es donde se escribe el código. El
 * truco es no usar `file_exists()` —que acá es case-insensitive y diría que
 * está todo bien— sino comparar los strings contra el listado real del
 * directorio.
 */

/**
 * Cada página que existe en disco, con su case exacto: 'settings/Appearance'.
 *
 * @return list<string>
 */
function paginasEnDisco(): array
{
    $raiz = resource_path('js/pages');

    return collect(File::allFiles($raiz))
        ->filter(fn ($archivo): bool => $archivo->getExtension() === 'vue')
        ->map(fn ($archivo): string => str_replace(
            [DIRECTORY_SEPARATOR, '.vue'],
            ['/', ''],
            substr($archivo->getPathname(), strlen($raiz) + 1),
        ))
        ->values()
        ->all();
}

/**
 * Cada componente que el código pide renderizar, con el archivo donde aparece.
 *
 * Se miran `app/` y `routes/`: este proyecto declara varias páginas con
 * `Route::inertia()`, que vive en las rutas y no en un controlador.
 *
 * @return array<string, string>
 */
function paginasRenderizadas(): array
{
    $encontradas = [];

    $archivos = array_merge(
        File::allFiles(app_path()),
        File::allFiles(base_path('routes')),
    );

    foreach ($archivos as $archivo) {
        if ($archivo->getExtension() !== 'php') {
            continue;
        }

        // Inertia::render('x/Y'), inertia('x/Y'), ->render('x/Y') y Route::inertia('url', 'x/Y').
        preg_match_all(
            '/(?:Inertia::render|->render|Route::inertia\(\s*[\'"][^\'"]*[\'"]\s*,|\binertia)\(?\s*[\'"]([^\'"]+)[\'"]/',
            (string) file_get_contents($archivo->getPathname()),
            $coincidencias,
        );

        foreach ($coincidencias[1] as $componente) {
            $encontradas[$componente] = $archivo->getFilename();
        }
    }

    return $encontradas;
}

it('renderiza cada página con el case exacto del archivo', function (): void {
    $enDisco = paginasEnDisco();
    $renderizadas = paginasRenderizadas();

    expect($renderizadas)->not->toBeEmpty('No se encontró ningún render: el patrón quedó viejo.');

    $desajustadas = [];

    foreach ($renderizadas as $componente => $donde) {
        // Comparación estricta de strings: es lo único que en Windows se
        // comporta igual que el filesystem de Linux.
        if (in_array($componente, $enDisco, true)) {
            continue;
        }

        $real = collect($enDisco)->first(
            fn (string $pagina): bool => strcasecmp($pagina, $componente) === 0,
        );

        $desajustadas[] = $real !== null
            ? "{$donde}: renderiza '{$componente}' y el archivo es '{$real}'"
            : "{$donde}: renderiza '{$componente}' y no existe ningún archivo así";
    }

    expect($desajustadas)->toBe([], implode("\n", $desajustadas));
});

it('no tiene dos páginas que solo difieran en mayúsculas', function (): void {
    // En Windows conviven solo por accidente de git; en Linux son dos archivos
    // distintos y el import apunta a uno solo.
    $enDisco = paginasEnDisco();

    expect(array_unique(array_map('strtolower', $enDisco)))->toHaveCount(count($enDisco));
});

it('busca las páginas en el directorio en minúscula', function (): void {
    // El default de Inertia es 'js/Pages' con P mayúscula. Si config/inertia.php
    // no apunta al case real, todo assertInertia falla en Linux.
    expect(config('inertia.pages.paths'))->toContain(resource_path('js/pages'))
        ->and(config('inertia.testing.ensure_pages_exist'))->toBeTrue();
});
