<!DOCTYPE html>
{{--
    `data-texto` lo escribe el servidor, no un script: a diferencia del modo
    oscuro no hay opción "según el sistema", así que acá ya se sabe la
    respuesta. Sin script no hay parpadeo de tamaño al cargar.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-texto="{{ ($tamanioTexto ?? \App\Enums\TamanioTexto::porDefecto())->value }}"
    @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        {{-- viewport-fit=cover habilita env(safe-area-inset-*), que en apaisado hacen falta a los costados --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        {{--
            El `?v=` no es decorativo. Sin él, la caché HTTP y la base de
            favicons de Chrome mobile —que es aparte y muy pegajosa— siguen
            sirviendo el ícono viejo para siempre, porque la URL no cambia.
            Al cambiar un ícono hay que subir este número, el del manifest y el
            nombre de CACHE en public/sw.js: los tres.
        --}}
        <link rel="icon" href="/favicon.svg?v=1" type="image/svg+xml">
        <link rel="icon" href="/icons/icon-192.png?v=1" type="image/png" sizes="192x192">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=1">

        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#0e7490">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="MiSalud">

        {{--
            Chrome dispara `beforeinstallprompt` apenas carga la página, casi
            siempre ANTES de que monte Vue. Si se escuchara desde un componente
            (onMounted) el evento ya pasó y el botón de instalar no aparece
            nunca — de forma intermitente, que es lo peor. Por eso se captura
            acá, antes de los bundles.
        --}}
        <script>
            (function () {
                window.__pwaInstall = { prompt: null, instalada: false };

                window.addEventListener('beforeinstallprompt', function (e) {
                    e.preventDefault(); // el prompt lo lanzamos desde el botón
                    window.__pwaInstall.prompt = e;
                    window.dispatchEvent(new CustomEvent('pwa:instalable'));
                });

                window.addEventListener('appinstalled', function () {
                    window.__pwaInstall.prompt = null;
                    window.__pwaInstall.instalada = true;
                    window.dispatchEvent(new CustomEvent('pwa:instalada'));
                });
            })();
        </script>

        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', function () {
                    navigator.serviceWorker.register('/sw.js').catch(function () {});
                });
            }
        </script>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
