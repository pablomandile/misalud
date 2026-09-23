<!DOCTYPE html>
{{--
    Pantalla que sirve el service worker cuando no hay conexión.

    Es una vista Blade suelta y no una página de Inertia, y eso es a propósito:
    tiene que poder dibujarse sin los assets compilados y sin que la app
    arranque. Todo el estilo va en línea por el mismo motivo.
--}}
<html lang="es" data-texto="{{ ($tamanioTexto ?? \App\Enums\TamanioTexto::porDefecto())->value }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title>Sin conexión — MiSalud</title>
        <style>
            :root { font-size: 112.5%; }
            html[data-texto='normal'] { font-size: 100%; }
            html[data-texto='muy-grande'] { font-size: 125%; }

            body {
                margin: 0;
                min-height: 100dvh;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 1.5rem;
                padding: 2rem calc(1.5rem + env(safe-area-inset-left)) 2rem calc(1.5rem + env(safe-area-inset-right));
                text-align: center;
                font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
                color: #18181b;
                background: #fff;
            }

            @media (prefers-color-scheme: dark) {
                body { color: #f4f4f5; background: #0a0a0a; }
            }

            h1 { margin: 0; font-size: 1.5rem; }
            p { margin: 0; max-width: 32rem; line-height: 1.5; opacity: .8; }

            a {
                display: inline-block;
                min-height: 44px;
                padding: .75rem 1.5rem;
                border-radius: .5rem;
                background: #0e7490;
                color: #fff;
                text-decoration: none;
                font-weight: 600;
            }
        </style>
    </head>
    <body>
        <img src="/icons/icon-192.png?v=1" alt="" width="72" height="72">

        <h1>No hay conexión</h1>

        <p>
            MiSalud necesita internet para mostrarte tus datos. No guardamos
            copias de la historia clínica en el teléfono: mostrarte un valor
            viejo sería peor que no mostrarte nada.
        </p>

        <a href="/dashboard">Volver a intentar</a>
    </body>
</html>
