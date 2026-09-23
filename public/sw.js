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
 *      **Excepción única y acotada: la credencial de la cobertura**, para
 *      verla en un mostrador sin señal, que es justo donde más hace falta.
 *      No es una excepción de este archivo: el propio servidor la separa en
 *      una ruta aparte (`/credenciales/{adjunto}`) que RECHAZA cualquier
 *      adjunto que no sea de tipo `credencial` (ver
 *      `AdjuntoController::showCredencial`), así que ni un bug acá ni una URL
 *      armada a mano pueden colar otro documento clínico por este camino.
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

/*
 * Caché APARTE para la credencial, y no una entrada más en `CACHE`.
 *
 * Dos motivos, ninguno de sobra: primero, `activate` borra toda caché que no
 * se llame `CACHE` en cada actualización de versión -si la credencial
 * viviera ahí, cada vez que se suba un ícono nuevo se perdería la foto de la
 * obra social de alguien-. Segundo, es la que se vacía puntualmente cuando
 * se borra una credencial (ver el listener de `message` más abajo): tiene
 * que poder limpiarse sola, sin arrastrar el resto.
 */
const CACHE_CREDENCIALES = 'misalud-credenciales-v1';

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
                        // CACHE_CREDENCIALES queda afuera a propósito: no es
                        // andamiaje de la app, es la foto de la credencial de
                        // una persona, y no tiene que perderse en cada deploy.
                        .filter(
                            (nombre) =>
                                nombre !== CACHE &&
                                nombre !== CACHE_CREDENCIALES,
                        )
                        .map((nombre) => caches.delete(nombre)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

/**
 * Le pide al service worker que olvide una credencial, cuando se borra desde
 * la app. Sin esto, borrar una credencial vieja la deja "disponible sin
 * señal" para siempre: la fila del servidor desaparece, pero la copia local
 * queda, y una app instalada casi nunca hace una recarga completa que la
 * renueve.
 */
self.addEventListener('message', (evento) => {
    const datos = evento.data;

    if (datos?.tipo === 'olvidar-credencial' && typeof datos.url === 'string') {
        evento.waitUntil(
            caches
                .open(CACHE_CREDENCIALES)
                .then((cache) => cache.delete(datos.url)),
        );
    }
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

/**
 * ¿Es la credencial? Se reconoce por el PATHNAME y nada más -no hace falta
 * preguntarle nada al servidor-, porque el servidor ya separó esta ruta de
 * `/adjuntos/{id}` puntualmente para que esto sea posible (ver
 * AdjuntoController::showCredencial). El servidor es quien de verdad decide
 * qué es cacheable, rechazando cualquier adjunto que no sea `credencial`;
 * acá solo hace falta reconocer el camino.
 */
function esCredencial(url) {
    return url.pathname.startsWith('/credenciales/');
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

    /*
     * La credencial: red primero -para que un cambio de plan o una
     * credencial nueva se vea apenas haya señal-, y la copia guardada como
     * respaldo únicamente si la red falla. Es el mismo patrón que ya usa
     * "todo lo demás" más abajo para lo estático, aplicado a esta única
     * excepción de contenido clínico.
     */
    if (esCredencial(url)) {
        evento.respondWith(
            fetch(request)
                .then((respuesta) => {
                    if (respuesta.ok) {
                        const copia = respuesta.clone();
                        caches
                            .open(CACHE_CREDENCIALES)
                            .then((cache) => cache.put(request, copia));
                    }

                    return respuesta;
                })
                .catch(() =>
                    caches
                        .open(CACHE_CREDENCIALES)
                        .then((cache) => cache.match(request))
                        .then((r) => r ?? Response.error()),
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
