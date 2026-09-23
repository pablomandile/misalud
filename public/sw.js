/*
 * Service worker de MiSalud.
 *
 * Es propio y no de `vite-plugin-pwa` a propósito: el plugin genera una
 * estrategia de caché pensada para sitios de contenido, y acá hay dos reglas
 * que no negocia ninguna receta genérica.
 *
 *   1. **Los datos clínicos no se cachean.** Mostrar una dosis vieja o un
 *      tratamiento dado de baja es peor que mostrar un cartel de "sin
 *      conexión". Solo se guarda lo que es andamiaje: el bundle y los íconos.
 *
 *   2. **`cache-first` únicamente para URLs con hash de contenido** (las de
 *      `/build/`). Cualquier URL fija —íconos, manifest, la portada— tiene que
 *      ir por red primero, o queda congelada para siempre: el `activate` solo
 *      borra cachés con OTRO nombre, así que una entrada vieja bajo la misma
 *      URL no la limpia nadie.
 *
 * Al cambiar los íconos hay que subir CACHE acá, el `?v=` de app.blade.php y el
 * `?v=` del manifest. Los tres, o se sigue viendo el ícono viejo.
 */

const CACHE = 'misalud-v1';

// Lo mínimo para que la pantalla de "sin conexión" pueda dibujarse sin red.
const BASICOS = [
    '/offline',
    '/manifest.webmanifest',
    '/icons/icon-192.png?v=1',
];

self.addEventListener('install', (evento) => {
    evento.waitUntil(
        caches
            .open(CACHE)
            .then((cache) => cache.addAll(BASICOS))
            .catch(() => {
                // Si alguno no está, el service worker se instala igual: no
                // vale la pena dejar la app sin SW por una precarga.
            }),
    );

    self.skipWaiting();
});

self.addEventListener('activate', (evento) => {
    evento.waitUntil(
        caches
            .keys()
            .then((nombres) =>
                Promise.all(
                    nombres
                        .filter((nombre) => nombre !== CACHE)
                        .map((nombre) => caches.delete(nombre)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

/**
 * ¿Es una navegación de verdad, de las que pintan un documento?
 *
 * ⚠️ NO alcanza con mirar el header `Accept`, que es el chequeo que traen casi
 * todos los service workers. El router de Inertia manda
 * `Accept: text/html, application/xhtml+xml` en sus XHR, así que ese chequeo da
 * `true` para cada navegación interna de la SPA y el service worker termina
 * "arreglando" respuestas que no tenía que tocar.
 */
function esNavegacion(request) {
    return request.mode === 'navigate';
}

/** Solo estas URLs llevan hash de contenido en el nombre. */
function tieneHashDeContenido(url) {
    return url.pathname.startsWith('/build/');
}

/*
 * Red de seguridad para el JSON crudo de Inertia.
 *
 * El parche de HandleInertiaRequests evita que se generen entradas MALAS
 * nuevas en la caché HTTP del navegador, pero no borra las que ya están
 * guardadas de antes del parche. Y cuando el bug ocurre la app nunca arranca,
 * así que ningún script de la página puede repararlo: el único que intercepta
 * la navegación antes de que llegue al navegador es este service worker.
 *
 * Dos condiciones, y ninguna sobra:
 *
 *   1. `request.mode !== 'navigate'` ya lo filtra `esNavegacion()` antes de
 *      llamar a esto, así que acá solo hace falta mirar el header de la
 *      RESPUESTA (`x-inertia`), no el content-type: una navegación real
 *      puede contestar JSON legítimamente (una exportación que se descarga),
 *      y ahí no hay que reintentar nada.
 *
 *   2. Si la reobtención viene redirigida (`recuperada.redirected`), no se le
 *      puede entregar así a una navegación -el Service Worker API lo
 *      prohíbe-, así que se sigue el redirect a mano. Es el caso más
 *      probable de todos: una pestaña con horas abiertas y la sesión vencida.
 */
function rescatarJsonCrudo(request, respuesta) {
    if (!respuesta.headers.get('x-inertia')) {
        return respuesta;
    }

    return fetch(request.url, {
        cache: 'reload',
        headers: { Accept: 'text/html' },
    }).then((recuperada) =>
        recuperada.redirected
            ? Response.redirect(recuperada.url, 302)
            : recuperada,
    );
}

self.addEventListener('fetch', (evento) => {
    const { request } = evento;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    // El bundle: cache-first, porque el nombre cambia cuando cambia el archivo.
    if (tieneHashDeContenido(url)) {
        evento.respondWith(
            caches.match(request).then(
                (guardada) =>
                    guardada ??
                    fetch(request).then((respuesta) => {
                        if (respuesta.ok) {
                            const copia = respuesta.clone();
                            caches
                                .open(CACHE)
                                .then((cache) => cache.put(request, copia));
                        }

                        return respuesta;
                    }),
            ),
        );

        return;
    }

    // Una navegación sin red muestra la pantalla de "sin conexión".
    if (esNavegacion(request)) {
        evento.respondWith(
            fetch(request)
                .then((respuesta) => rescatarJsonCrudo(request, respuesta))
                .catch(() =>
                    caches.match('/offline').then((r) => r ?? Response.error()),
                ),
        );

        return;
    }

    /*
     * Todo lo demás: red primero, y la caché solo como respaldo si no hay red.
     * Se guarda únicamente lo estático de URL fija (íconos, manifest). Las
     * respuestas de la app **no se guardan**: son datos clínicos.
     */
    evento.respondWith(
        fetch(request)
            .then((respuesta) => {
                const esEstatico =
                    url.pathname.startsWith('/icons/') ||
                    url.pathname === '/manifest.webmanifest' ||
                    url.pathname === '/favicon.svg';

                if (respuesta.ok && esEstatico) {
                    const copia = respuesta.clone();
                    caches
                        .open(CACHE)
                        .then((cache) => cache.put(request, copia));
                }

                return respuesta;
            })
            .catch(() =>
                caches.match(request).then((r) => r ?? Response.error()),
            ),
    );
});
