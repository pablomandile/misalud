/*
 * Revisión mobile de MiSalud, en un navegador de verdad.
 *
 * Comprueba dos cosas que ningún test de PHP puede ver:
 *
 *   1. Que no haya scroll horizontal en la matriz completa de anchos por
 *      tamaños de letra por orientación. La matriz no es exceso de celo: los
 *      breakpoints de Tailwind se miden en px contra el viewport y NO escalan
 *      con el tamaño de letra, así que el caso "pantalla chica + letra muy
 *      grande" no lo cubre ningún `sm:`. Ya encontró un botón que se salía
 *      16 px a 320 px.
 *
 *   2. Que ningún control quede por debajo de 44 px de alto efectivo. Se mide
 *      el alto EFECTIVO con `elementFromPoint` y no la caja: un checkbox de
 *      16 px con el área expandida por un pseudo-elemento se toca bien, y su
 *      caja igual mide 16. Ya encontró que NINGUNA pantalla cumplía.
 *
 *   3. Que el menú hamburguesa se cierre al navegar, y que la barra lateral de
 *      escritorio NO se cierre. En una SPA la navegación no desmonta el sheet:
 *      la pantalla nueva carga detrás y el menú queda encima con el scroll del
 *      body bloqueado.
 *
 * Uso:
 *
 *   node scripts/revisar-mobile.mjs
 *   BASE=http://127.0.0.1:8001 EMAIL=... PASSWORD=... node scripts/revisar-mobile.mjs
 *
 * Necesita puppeteer-core y un Chrome instalado. Si no está:
 *
 *   npm i -D puppeteer-core
 */

let puppeteer;

try {
    ({ default: puppeteer } = await import('puppeteer-core'));
} catch {
    console.error(
        'Falta puppeteer-core. Instalalo con: npm i -D puppeteer-core',
    );
    process.exit(2);
}

const CHROME =
    process.env.CHROME ??
    'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = process.env.BASE ?? 'http://127.0.0.1:8001';
const EMAIL = process.env.EMAIL ?? 'prueba@misalud.test';
const PASSWORD = process.env.PASSWORD ?? 'prueba-1234';

/*
 * Las pantallas de invitado se miden ANTES de iniciar sesión, en su propia
 * pasada. Estaban en la misma lista que el resto y por eso nunca se midieron:
 * con la sesión ya abierta, /login y /register redirigen al dashboard, así que
 * la matriz decía "sin desborde" sobre la pantalla equivocada. Es la primera
 * que ve cualquiera, y la que más texto apila cuando la letra es muy grande.
 */
const RUTAS_INVITADO = ['/login', '/register'];
const RUTAS = [
    '/dashboard',
    '/pacientes',
    '/medicos',
    '/centros',
    '/settings/appearance',
];
const ANCHOS = [320, 360, 414];
const TAMANIOS = ['normal', 'grande', 'muy-grande'];
const MINIMO = 44;
/* El mismo valor que TamanioTexto::PORDEFECTO. */
const POR_DEFECTO = 'grande';

let problemas = 0;

const navegador = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox'],
});

const pagina = await navegador.newPage();

async function ingresar(pagina) {
    await pagina.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
    await pagina.type('input[name="email"]', EMAIL);
    await pagina.type('input[name="password"]', PASSWORD);

    /*
     * `data-test` y no `button[type=submit]`: en esta pantalla hay más de un
     * botón y el orden puede cambiar. Y se espera por la URL, no con
     * `waitForNavigation`: el submit de Inertia es un XHR y no dispara el
     * evento de navegación, así que la espera vence y parece que falló.
     */
    await pagina.click('[data-test="login-button"]');

    await pagina
        .waitForFunction(() => !location.pathname.startsWith('/login'), {
            timeout: 10000,
        })
        .catch(() => {});

    return !pagina.url().includes('/login');
}

async function iniciarSesion() {
    await pagina.setViewport({ width: 390, height: 844 });

    if (!(await ingresar(pagina))) {
        console.error(
            `No se pudo iniciar sesión con ${EMAIL}. Pasá EMAIL y PASSWORD, o creá ese usuario.`,
        );
        await navegador.close();
        process.exit(2);
    }
}

/*
 * Cambiar el tamaño de letra. La cookie NO alcanza cuando hay sesión.
 *
 * HandleTamanioTexto resuelve "manda la cuenta, después la cookie": con la
 * sesión abierta gana la columna del usuario y la cookie se ignora. Poniendo
 * solo la cookie, las tres vueltas del bucle medían EXACTAMENTE lo mismo -la
 * matriz informaba 54 combinaciones y en realidad eran 18 repetidas tres
 * veces, ciega a dos de los tres tamaños en toda pantalla con sesión-. No daba
 * ningún síntoma: informaba de mas y en verde.
 *
 * Con sesión se cambia por el mismo endpoint que usa la pantalla de
 * configuración, que escribe la columna Y la cookie.
 */
async function aplicarTamanio(tamanio, conSesion) {
    await pagina.setCookie({
        name: 'tamanio_texto',
        value: tamanio,
        url: BASE,
    });

    if (!conSesion) {
        return;
    }

    const falla = await pagina.evaluate(async (tamanio) => {
        const cookie = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

        if (!cookie) {
            return 'no hay cookie XSRF-TOKEN';
        }

        const r = await fetch('/tamanio-texto', {
            method: 'PUT',
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(cookie[1]),
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ tamanio_texto: tamanio }),
            /*
             * `manual` y no el `follow` por defecto. El controlador contesta
             * con el 302 de `back()`, y al seguirlo fetch convierte el PUT en
             * GET -lo manda la spec para un 302- contra la misma URL, que solo
             * acepta PUT: 405. El cambio ya se había aplicado; lo que fallaba
             * era la vuelta.
             */
            redirect: 'manual',
        });

        // Un 302 opaco es exactamente lo que devuelve `back()`: es el caso bueno.
        if (r.ok || r.type === 'opaqueredirect') {
            return null;
        }

        return `HTTP ${r.status}`;
    }, tamanio);

    if (falla) {
        console.error(`   No se pudo cambiar el tamaño de letra: ${falla}`);
        await navegador.close();
        process.exit(2);
    }
}

async function revisarDesborde(rutas, etiqueta, conSesion) {
    console.log(`== Desborde horizontal (${etiqueta})`);

    const fallas = [];

    for (const [orientacion, apaisado] of [
        ['vertical', false],
        ['apaisado', true],
    ]) {
        for (const ancho of ANCHOS) {
            await pagina.setViewport({
                width: apaisado ? 844 : ancho,
                height: apaisado ? ancho : 844,
            });

            for (const tamanio of TAMANIOS) {
                await aplicarTamanio(tamanio, conSesion);

                for (const ruta of rutas) {
                    await pagina.goto(BASE + ruta, {
                        waitUntil: 'networkidle0',
                    });

                    const dato = await pagina.evaluate(() => {
                        const exceso =
                            document.documentElement.scrollWidth -
                            window.innerWidth;

                        if (exceso <= 0) {
                            return { exceso: 0, culpable: null };
                        }

                        for (const el of document.querySelectorAll('*')) {
                            const caja = el.getBoundingClientRect();

                            if (
                                caja.right > window.innerWidth + 1 &&
                                caja.width > 0
                            ) {
                                return {
                                    exceso,
                                    culpable: `${el.tagName} "${(el.innerText || '').trim().slice(0, 30)}"`,
                                };
                            }
                        }

                        return { exceso, culpable: '(no identificado)' };
                    });

                    if (dato.exceso > 0) {
                        fallas.push({
                            orientacion,
                            ancho,
                            tamanio,
                            ruta,
                            exceso: `${dato.exceso}px`,
                            culpable: dato.culpable,
                        });
                    }
                }
            }
        }
    }

    const total = rutas.length * ANCHOS.length * TAMANIOS.length * 2;

    if (fallas.length === 0) {
        console.log(`   sin desborde en ${total} combinaciones\n`);

        return;
    }

    console.table(fallas);
    problemas += fallas.length;
}

/*
 * Áreas táctiles de 44 px.
 *
 * Se mide el alto EFECTIVO y no `getBoundingClientRect().height`, y la
 * diferencia importa: un checkbox de 16 px con el área expandida por un
 * pseudo-elemento se toca perfecto, pero su caja sigue midiendo 16. Los
 * pseudo-elementos no están en el DOM, así que la única forma honesta de
 * saber si algo se puede tocar es preguntarle al navegador qué hay en un
 * punto: `elementFromPoint`, que sí los resuelve.
 *
 * Quedan afuera a propósito:
 *
 *   - Lo que no se ve (tamaño cero, `sr-only`, el input nativo que reka-ui
 *     esconde detrás de cada checkbox).
 *   - Los enlaces EN LÍNEA dentro de un texto. WCAG 2.5.8 los exceptúa, y con
 *     razón: "¿Olvidaste tu contraseña?" adentro de una oración no puede medir
 *     44 px de alto sin romper el renglón. Se los reconoce por `display:
 *     inline` — un `inline-flex` o un `inline-block` es un botón disfrazado y
 *     sí se mide.
 */
async function revisarAreasTactiles(rutas, etiqueta, conSesion) {
    console.log(`== Áreas táctiles de ${MINIMO} px (${etiqueta})`);

    const fallas = [];

    // El ancho no cambia el alto de un control; el tamaño de letra sí. Por eso
    // acá se recorren los tres tamaños y un solo ancho, el más chico.
    await pagina.setViewport({ width: 320, height: 844 });

    for (const tamanio of TAMANIOS) {
        await aplicarTamanio(tamanio, conSesion);

        for (const ruta of rutas) {
            await pagina.goto(BASE + ruta, { waitUntil: 'networkidle0' });

            const chicos = await pagina.evaluate((MINIMO) => {
                const visible = (el, caja) => {
                    if (caja.width < 3 || caja.height < 3) {
                        return false;
                    }

                    const e = getComputedStyle(el);

                    return (
                        e.visibility !== 'hidden' &&
                        e.display !== 'none' &&
                        Number(e.opacity) > 0
                    );
                };

                /* Hasta dónde llega el área que realmente responde al toque. */
                const altoEfectivo = (el, caja) => {
                    const cx = caja.left + caja.width / 2;
                    const cy = caja.top + caja.height / 2;
                    const suyo = (x, y) => {
                        const otro = document.elementFromPoint(x, y);

                        return !!otro && (otro === el || el.contains(otro));
                    };

                    const estirar = (signo) => {
                        let d = caja.height / 2;

                        while (d < MINIMO && suyo(cx, cy + signo * (d + 2))) {
                            d += 2;
                        }

                        return d;
                    };

                    return Math.round(estirar(-1) + estirar(1));
                };

                return [
                    ...document.querySelectorAll(
                        'button, a[href], input, select, textarea, [role="button"]',
                    ),
                ]
                    .map((el) => {
                        const caja = el.getBoundingClientRect();

                        if (!visible(el, caja)) {
                            return null;
                        }

                        // Enlace en línea dentro de un texto: exento.
                        if (
                            el.tagName === 'A' &&
                            getComputedStyle(el).display === 'inline'
                        ) {
                            return null;
                        }

                        const alto = altoEfectivo(el, caja);

                        if (alto >= MINIMO) {
                            return null;
                        }

                        return {
                            alto: `${alto}px`,
                            que: `${el.tagName.toLowerCase()} "${(el.innerText || el.getAttribute('aria-label') || el.getAttribute('name') || '').trim().slice(0, 28)}"`,
                        };
                    })
                    .filter(Boolean);
            }, MINIMO);

            for (const chico of chicos) {
                fallas.push({ tamanio, ruta, ...chico });
            }
        }
    }

    if (fallas.length === 0) {
        console.log(
            `   todo llega a ${MINIMO} px en ${rutas.length * TAMANIOS.length} pantallas\n`,
        );

        return;
    }

    console.table(fallas);
    problemas += fallas.length;
}

async function revisarMenu() {
    console.log('== Menú al navegar');

    const estado = () =>
        pagina.evaluate(() => {
            const estilo = getComputedStyle(document.body);
            const sheet = document.querySelector('[data-mobile="true"]');

            return {
                abierto: !!sheet && sheet.getBoundingClientRect().width > 0,
                bloqueado:
                    estilo.overflow === 'hidden' ||
                    estilo.pointerEvents === 'none',
            };
        });

    await pagina.setViewport({ width: 390, height: 844 });
    await pagina.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });

    await pagina.evaluate(() =>
        document.querySelector('header button')?.click(),
    );
    await new Promise((r) => setTimeout(r, 600));

    if (!(await estado()).abierto) {
        console.log('   ✗ no se pudo abrir el menú: revisá el selector\n');
        problemas++;

        return;
    }

    await pagina.evaluate(() =>
        document
            .querySelector('[data-mobile="true"] a[href*="settings"]')
            ?.click(),
    );
    await new Promise((r) => setTimeout(r, 1500));

    const después = await estado();

    console.log(
        después.abierto
            ? '   ✗ el menú quedó abierto tapando la pantalla nueva'
            : '   ✓ el menú se cerró al navegar',
    );
    console.log(
        después.bloqueado
            ? '   ✗ el body quedó con el scroll bloqueado'
            : '   ✓ el body responde',
    );

    if (después.abierto || después.bloqueado) {
        problemas++;
    }

    // En escritorio la barra es fija: cerrarla al navegar dejaría sin menú.
    await pagina.setViewport({ width: 1280, height: 800 });
    await pagina.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });
    await pagina.evaluate(() =>
        document.querySelector('[data-slot="sidebar"] a')?.click(),
    );
    await new Promise((r) => setTimeout(r, 1200));

    const anchoBarra = await pagina.evaluate(
        () =>
            document
                .querySelector('[data-slot="sidebar"]')
                ?.getBoundingClientRect().width ?? 0,
    );

    console.log(
        anchoBarra > 0
            ? `   ✓ en escritorio la barra sigue visible (${Math.round(anchoBarra)}px)\n`
            : '   ✗ en escritorio la barra se cerró al navegar\n',
    );

    if (anchoBarra === 0) {
        problemas++;
    }
}

await revisarDesborde(RUTAS_INVITADO, 'sin sesión', false);
await revisarAreasTactiles(RUTAS_INVITADO, 'sin sesión', false);
await iniciarSesion();
await revisarDesborde(RUTAS, 'con sesión', true);
await revisarAreasTactiles(RUTAS, 'con sesión', true);

/*
 * Volver al tamaño por defecto antes del último chequeo. Los bucles de arriba
 * dejan la cuenta en el ÚLTIMO tamaño que probaron, así que sin esto el chequeo
 * del menú corre en "muy grande" por arrastre -y el usuario de prueba queda con
 * esa preferencia guardada para la próxima corrida-.
 */
await aplicarTamanio(POR_DEFECTO, true);
await revisarMenu();
await navegador.close();

console.log(problemas === 0 ? 'Todo en orden.' : `${problemas} problema(s).`);
process.exit(problemas === 0 ? 0 : 1);
